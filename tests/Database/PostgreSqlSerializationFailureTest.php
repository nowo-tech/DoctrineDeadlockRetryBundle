<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\Counter;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\DatabaseTestCase;

/**
 * PostgreSQL: a real serialization failure (SQLSTATE 40001) in a nested flush under REPEATABLE READ. PostgreSQL keeps
 * the savepoints, so the ORM's rollbacks succeed; the whole transaction is replayed.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class PostgreSqlSerializationFailureTest extends DatabaseTestCase
{
    public function testSerializationFailureInNestedFlushIsRetried(): void
    {
        $this->boot('DDRB_POSTGRES_URL');
        $service  = $this->service();
        $attempts = 0;

        $service->retry(function () use ($service, &$attempts): void {
            ++$attempts;
            $attempt = $attempts;

            $service->getEntityManager()->wrapInTransaction(function (EntityManagerInterface $manager) use ($attempt): void {
                $manager->getConnection()->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $counter = $manager->find(Counter::class, 1);
                self::assertInstanceOf(Counter::class, $counter);

                if ($attempt === 1) {
                    self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = counter_value + 100 WHERE id = 1'));
                }

                ++$counter->value;
                $manager->flush();
            });
        });

        self::assertSame(2, $attempts);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(101, $this->committedValue(1));

        $manager = $service->getEntityManager();
        $counter = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $counter);
        $counter->value = 9;
        $manager->flush();
        self::assertSame(9, $this->committedValue(2));
    }
}
