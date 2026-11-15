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
