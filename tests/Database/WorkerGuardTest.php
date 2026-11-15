<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\WorkerGuardSubscriber;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\CollectingLogger;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\Counter;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\DatabaseTestCase;
use Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * The worker guard repairs a connection a previous request left poisoned (nesting level 2 after a nested deadlock,
 * or a forgotten open transaction) and resets the closed entity manager, as a FrankenPHP worker with kernel reset
 * false needs.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WorkerGuardTest extends DatabaseTestCase
{
    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new CollectingLogger();
    }

    #[DataProvider('mysqlFamily')]
    public function testTerminateRepairsAConnectionPoisonedByANestedDeadlock(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();

        try {
            $manager->wrapInTransaction(function (EntityManagerInterface $manager): void {
                $manager->getConnection()->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
                $this->startDeadlockingPeer();
                $counter = $manager->find(Counter::class, 2);
                self::assertInstanceOf(Counter::class, $counter);
                ++$counter->value;
                $manager->flush();
            });
            self::fail('The nested flush should have deadlocked.');
        } catch (Throwable) {
            // The 1305 from the failed ROLLBACK TO SAVEPOINT, as a request would see it.
        }

        self::assertSame('OK', $this->otherSession()->await());
        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        self::assertGreaterThanOrEqual(2, $this->connection->getTransactionNestingLevel(), 'Precondition: the connection is poisoned.');
        self::assertFalse($manager->isOpen());

        $this->subscriber()->onKernelTerminate(new TerminateEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), new Response()));

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertTrue($this->manager()->isOpen());
        self::assertCount(2, $this->logger->records, 'One warning for the connection, one for the closed entity manager.');
        self::assertSame('default', $this->logger->records[1][2]['manager'] ?? null);
        [$level, $message, $context] = $this->logger->records[0];
        self::assertSame('warning', $level);
        self::assertSame('closed', $context['action']);
        self::assertSame('kernel.terminate', $context['phase']);
        self::assertStringNotContainsStringIgnoringCase('ddrb_counter', $message . json_encode($context));

        // The next request on the same worker connection commits for real (not inside a ghost savepoint).
        $next    = $this->manager();
        $counter = $next->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $counter);
        $counter->value = 42;
        $next->flush();

        self::assertSame(42, $this->committedValue(1));
    }

    #[DataProvider('mysqlFamily')]
    public function testRequestRollsBackAForgottenTransaction(string $server): void
    {
        $this->boot($server);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 13 WHERE id = 1');

        $this->subscriber()->onKernelRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST));

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(0, $this->committedValue(1), 'The forgotten write is rolled back, not committed.');
        self::assertSame('rolled_back', $this->logger->records[0][2]['action'] ?? null);
        self::assertSame('kernel.request', $this->logger->records[0][2]['phase'] ?? null);
    }

    #[DataProvider('allServers')]
    public function testTerminateClearsPendingChangesSoTheNextRequestDoesNotCommitThem(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();
        $manager->persist(new Counter(77));   // a failed unit of work left this behind, unflushed

        $this->subscriber(clearManagers: true)->onKernelTerminate(new TerminateEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), new Response()));

        self::assertSame([], $manager->getUnitOfWork()->getScheduledEntityInsertions());
        self::assertSame('default', $this->logger->records[0][2]['manager'] ?? null);

        $manager->flush();   // the next request's flush
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 77'));
    }

    private function subscriber(bool $clearManagers = false): WorkerGuardSubscriber
    {
        return new WorkerGuardSubscriber(new ConnectionGuard($this->registry, $this->logger, clearManagers: $clearManagers));
    }
}
