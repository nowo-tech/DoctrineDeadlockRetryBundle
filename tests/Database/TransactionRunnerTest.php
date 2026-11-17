<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureClassifier;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\Counter;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\DatabaseTestCase;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\Sleep\RecordingSleeper;
use Nowo\DoctrineDeadlockRetryBundle\Transaction\DeadlockRetryTransactionRunner;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

/**
 * The TransactionRunnerInterface adapter against a real nested deadlock on MySQL / MariaDB.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransactionRunnerTest extends DatabaseTestCase
{
    #[DataProvider('mysqlFamily')]
    public function testRunnerReplaysAUnitOfWorkThatDeadlocked(string $server): void
    {
        $this->boot($server);
        $service  = $this->service();
        $runner   = new DeadlockRetryTransactionRunner($service, new TransientFailureClassifier());
        $attempts = 0;

        $result = $runner->run(function () use ($service, &$attempts): string {
            ++$attempts;

            if ($attempts === 2) {
                self::assertSame('OK', $this->otherSession()->await());
                self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
            }

            $this->deadlockingWork($service->getEntityManager(), $attempts === 1);

            return 'paid';
        });

        self::assertSame('paid', $result);
        self::assertSame(2, $attempts);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(101, $this->committedValue(2));
    }

    #[DataProvider('mysqlFamily')]
    public function testExhaustedRunnerThrowsTransientStorageFailure(string $server): void
    {
        $this->boot($server);
        $service = $this->service(maxRetries: 0);
        $runner  = new DeadlockRetryTransactionRunner($service, new TransientFailureClassifier(), 4);
        $caught  = null;

        try {
            $runner->run(function () use ($service): void {
                $this->deadlockingWork($service->getEntityManager(), true);
            });
        } catch (TransientStorageFailure $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf(TransientStorageFailure::class, $caught);
        self::assertSame(4, $caught->retryAfterSeconds());
        self::assertStringNotContainsStringIgnoringCase('ddrb_counter', $caught->getMessage());
        self::assertSame('OK', $this->otherSession()->await());
        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * Beacon's WriteUnit case: a real deadlock that reaches the runner inside a Messenger HandlerFailedException (a
     * handler dispatched on a sync bus), the 3-retry profile with 20–200 ms full jitter, a recording sleeper.
     */
    #[DataProvider('mysqlFamily')]
    public function testWriteUnitProfileReplaysADeadlockWrappedByMessenger(string $server): void
    {
        $this->boot($server);
        $sleeper = new RecordingSleeper();
        $service = new DeadlockRetryService(
            $this->manager(),
            ['write_unit' => new RetryProfile('write_unit', 3, 200, backoffMaxMs: 200, retryLockWaitTimeout: true, backoffMinMs: 20)],
            'write_unit',
            $this->registry,
            sleeper: $sleeper,
        );
        $runner   = new DeadlockRetryTransactionRunner($service, new TransientFailureClassifier());
        $attempts = 0;

        $result = $runner->run(function () use ($service, &$attempts): string {
            ++$attempts;

            if ($attempts === 2) {
                self::assertSame('OK', $this->otherSession()->await());
                self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
            }

            try {
                $this->deadlockingWork($service->getEntityManager(), $attempts === 1);
            } catch (Throwable $handlerFailure) {
                throw new HandlerFailedException(new Envelope(new stdClass()), ['audit' => new LogicException('audit handler'), 'pay' => $handlerFailure]);
            }

            return 'paid';
        });

        self::assertSame('paid', $result);
        self::assertSame(2, $attempts);
        self::assertCount(1, $sleeper->delays);
        self::assertGreaterThanOrEqual(20, $sleeper->delays[0]);
        self::assertLessThanOrEqual(200, $sleeper->delays[0]);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(101, $this->committedValue(2));
    }

    private function deadlockingWork(EntityManagerInterface $manager, bool $withPeer): void
    {
        $manager->getConnection()->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');

        if ($withPeer) {
            $this->startDeadlockingPeer();
        }

        $counter = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $counter);
        ++$counter->value;
        $manager->flush();
    }
}
