<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureClassifier;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\Counter;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\DatabaseTestCase;
use Nowo\DoctrineDeadlockRetryBundle\Transaction\DeadlockRetryTransactionRunner;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Throwable;

use function array_reverse;

/**
 * Regression tests for audit SB of 3.1.0 (probes P1 and P3), against real MySQL / MariaDB: work that may already be
 * committed is never replayed.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class MessengerReplayTest extends DatabaseTestCase
{
    /**
     * P1 (SB-M1): a write is committed, then a TransientStorageFailure over a non-database (cache) error escapes. It is
     * not retried: the write is applied once.
     */
    #[DataProvider('mysqlFamily')]
    public function testTransientStorageFailureOverACacheErrorRunsExactlyOnce(string $server): void
    {
        $this->boot($server);
        $service  = $this->service();
        $attempts = 0;
        $caught   = null;

        try {
            $service->retry(static function () use ($service, &$attempts): never {
                ++$attempts;
                $service->getEntityManager()->getConnection()->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 2');

                throw TransientStorageFailure::fromPrevious(new RuntimeException('cache backend down'));
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(TransientStorageFailure::class, $caught);
        self::assertSame(1, $attempts);
        self::assertSame(1, $this->committedValue(2));
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function busVariants(): iterable
    {
        foreach (self::mysqlFamily() as $label => [$environmentVariable]) {
            yield $label . ', retry(), notify first' => [$environmentVariable, false, true];
            yield $label . ', retry(), pay first' => [$environmentVariable, false, false];
            yield $label . ', runner, notify first' => [$environmentVariable, true, true];
            yield $label . ', runner, pay first' => [$environmentVariable, true, false];
        }
    }

    /**
     * P3 (SB-M2): a synchronous bus with two handlers on one message; "notify" succeeds (writes a log row), "pay"
     * deadlocks. The HandlerFailedException carries notify's HandledStamp: the dispatch is not replayed, so notify's
     * row is never written twice.
     */
    #[DataProvider('busVariants')]
    public function testADispatchWhereAnotherHandlerCompletedIsNotReplayed(string $server, bool $useRunner, bool $notifyFirst): void
    {
        $this->boot($server);
        $this->connection->executeStatement('DROP TABLE IF EXISTS ddrb_probe_log');
        $this->connection->executeStatement('CREATE TABLE ddrb_probe_log (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, tag VARCHAR(20) NOT NULL)');
        $service  = $this->service();
        $attempts = 0;
        $notify   = static function () use ($service): void {
            $service->getEntityManager()->getConnection()->executeStatement("INSERT INTO ddrb_probe_log (tag) VALUES ('notify')");
        };
        $pay = function () use ($service): void {
            $work = function (EntityManagerInterface $manager): void {
                $manager->getConnection()->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
                $this->startDeadlockingPeer();
                $counter = $manager->find(Counter::class, 2);
                self::assertInstanceOf(Counter::class, $counter);
                ++$counter->value;
                $manager->flush();
            };
            $manager = $service->getEntityManager();
            $manager->getConnection()->isTransactionActive() ? $work($manager) : $manager->wrapInTransaction($work);
        };
        $handlers = [new HandlerDescriptor($notify, ['alias' => 'notify']), new HandlerDescriptor($pay, ['alias' => 'pay'])];
        $bus      = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([stdClass::class => $notifyFirst ? $handlers : array_reverse($handlers)]))]);
        $dispatch = static function () use ($bus, &$attempts): void {
            ++$attempts;
            $bus->dispatch(new stdClass());
        };
        $caught = null;

        try {
            $useRunner
                ? (new DeadlockRetryTransactionRunner($service, new TransientFailureClassifier()))->run($dispatch)
                : $service->retry($dispatch);
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            self::assertSame('OK', $this->otherSession()->await());
            self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        }

        // retry(): the HandlerFailedException escapes as is. Runner: wrapInTransaction()'s rollback of the nested level
        // fails with 1305 (the deadlock dropped the savepoint) and replaces it; a bare 1305 is not transient either.
        self::assertInstanceOf($useRunner ? Throwable::class : HandlerFailedException::class, $caught);
        self::assertSame(1, $attempts);
        // Under the runner with notify first, the deadlock rolls notify's row back with the transaction; with pay first,
        // notify's INSERT runs after the server ended the transaction and is auto-committed (ghost transaction).
        self::assertLessThanOrEqual(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_probe_log'));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        $this->connection->executeStatement('DROP TABLE IF EXISTS ddrb_probe_log');
    }
}
