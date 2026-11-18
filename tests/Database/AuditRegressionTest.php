<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\TransientFailureExceptionListener;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureClassifier;
use Nowo\DoctrineDeadlockRetryBundle\Http\HasErrorCodeInterface;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\Counter;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * Regression tests for the 2026-11-15 QA audit of 3.0.0 (APPENDIX-QA §1), ported from its proofs of concept with
 * the assertions inverted: each test passes when the finding is fixed.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class AuditRegressionTest extends DatabaseTestCase
{
    /**
     * QA-DDRB-M1: savepoints also vanish on an implicit commit (DDL inside the transaction). The resulting 1305 is not
     * transient on its own: the unique violation is neither retried nor mapped to a lock conflict, and the committed work is
     * not replayed.
     */
    #[DataProvider('mysqlFamily')]
    public function testLostSavepointWithoutADeadlockIsNotRetried(string $server): void
    {
        $this->boot($server);
        $this->connection->executeStatement('DROP TABLE IF EXISTS qa_audit');
        $this->connection->executeStatement('DROP TABLE IF EXISTS qa_lazy');
        $this->connection->executeStatement('CREATE TABLE qa_audit (id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(20) NOT NULL)');
        $attempts = 0;
        $failure  = null;

        try {
            $this->service(maxRetries: 3)->retry(function () use (&$attempts): void {
                ++$attempts;
                $this->connection->transactional(static function (Connection $connection): void {
                    $connection->insert('qa_audit', ['note' => 'charged']);
                    $connection->transactional(static function (Connection $connection): void {
                        $connection->executeStatement('CREATE TABLE IF NOT EXISTS qa_lazy (id INT)');   // implicit commit
                        $connection->insert('ddrb_counter', ['id' => 1, 'counter_value' => 0]);         // 1062
                    });
                });
            });
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        self::assertInstanceOf(Throwable::class, $failure);
        self::assertNull((new TransientFailureClassifier())->classify($failure));
        self::assertSame(1, $attempts, 'A unique violation is not retried.');
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM qa_audit'), 'The committed work is not replayed.');
        self::assertSame(0, $this->connection->getTransactionNestingLevel());

        $listener = new TransientFailureExceptionListener(conflictExceptions: [UniqueConstraintViolationException::class]);
        $event    = new ExceptionEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(409, $mapped->getStatusCode());
        self::assertSame('RESOURCE_CHANGED', $mapped instanceof HasErrorCodeInterface ? $mapped->errorCode() : null, 'A domain 409 with its own code: a unique violation is never CONFLICT_*.');
    }

    /**
     * QA-DDRB-M2: a raw DBAL transient failure leaves the entity manager open; retry() clears it before replaying, so
     * the first attempt's persisted entity is not inserted a second time.
     */
    #[DataProvider('mysqlFamily')]
    public function testRetryClearsADirtyOpenManagerBeforeReplaying(string $server): void
    {
        $this->boot($server);
        $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 99 WHERE id = 1'));
        $service  = $this->service(retryLockWaitTimeout: true);
        $attempts = 0;

        $service->retry(function () use ($service, &$attempts): void {
            ++$attempts;

            if ($attempts === 2) {
                self::assertSame('OK', $this->otherSession()->execute('ROLLBACK'));
            }

            $manager = $service->getEntityManager();
            $manager->persist(new Counter(100 + $attempts));
            $manager->getConnection()->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
            $manager->flush();
        });

        self::assertSame(2, $attempts);
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id >= 100'), 'One logical insert, one row.');
    }

    /**
     * QA-DDRB-M3: with rollback_on_deadlock false the service does not repair the connection, so it must not retry on
     * it either: no replay runs inside ghost savepoints and nothing is auto-committed.
     */
    #[DataProvider('mysqlFamily')]
    public function testNoRepairProfileNeverRetriesOnAPoisonedConnection(string $server): void
    {
        $this->boot($server);
        $service = new DeadlockRetryService(
            $this->manager(),
            ['default' => new RetryProfile('default', 3, 0, rollbackOnDeadlock: false)],
            'default',
            $this->registry,
        );
        $attempts = 0;
        $failure  = null;

        try {
            $service->retry(function () use ($service, &$attempts): void {
                ++$attempts;
                $service->getEntityManager()->wrapInTransaction(function (EntityManagerInterface $manager): void {
                    $manager->getConnection()->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
                    $this->startDeadlockingPeer();
                    $counter = $manager->find(Counter::class, 2);
                    self::assertInstanceOf(Counter::class, $counter);
                    ++$counter->value;
                    $manager->flush();
                });
            });
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        self::assertInstanceOf(Throwable::class, $failure);
        self::assertSame(1, $attempts, 'No retry on a connection the service did not repair.');
        self::assertSame('OK', $this->otherSession()->await());
        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        self::assertSame(100, $this->committedValue(1), 'Nothing was auto-committed by a ghost replay.');
    }

    /**
     * QA-DDRB-L1: with auto-commit disabled the connection sits at level 1 between units of work; that is the
     * outermost level, so transient failures are retried (after rolling the implicit transaction back).
     */
    #[DataProvider('mysqlFamily')]
    public function testAutoCommitOffConnectionIsRetried(string $server): void
    {
        $this->boot($server);
        $this->connection->setAutoCommit(false);
        $this->connection->close();
        $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 99 WHERE id = 1'));
        $attempts = 0;

        $this->service(retryLockWaitTimeout: true)->retry(function () use (&$attempts): void {
            if (++$attempts === 2) {
                self::assertSame('OK', $this->otherSession()->execute('ROLLBACK'));
            }

            $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 5 WHERE id = 1');
        });
        $this->connection->commit();

        self::assertSame(2, $attempts);
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        self::assertSame(5, $this->committedValue(1));
    }

    /**
     * QA-TD-M1 applied to the service: a domain failure that leaves a transaction open is rolled back, not closed,
     * so session variables and session-level locks survive.
     */
    #[DataProvider('allServers')]
    public function testDomainFailureRollsBackAndKeepsSessionLocks(string $server): void
    {
        $this->boot($server);

        if ($this->isPostgreSql()) {
            $this->connection->fetchOne('SELECT pg_advisory_lock(4242)');
        } else {
            $this->connection->executeStatement("SET @ddrb_session = 'kept'");
            $this->connection->fetchOne("SELECT GET_LOCK('ddrb_qa_lock', 0)");
        }

        $caught = null;

        try {
            $this->service()->retry(function (): never {
                $this->connection->beginTransaction();
                $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 13 WHERE id = 1');

                throw new DomainException('validation failed');
            });
        } catch (DomainException $exception) {
            $caught = $exception;
        }

        self::assertSame('validation failed', $caught->getMessage());

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(0, $this->committedValue(1), 'The write was rolled back.');

        if ($this->isPostgreSql()) {
            self::assertFalse((bool) $this->freshValue('SELECT pg_try_advisory_lock(4242)'), 'The advisory lock is still held.');
        } else {
            self::assertSame('kept', $this->connection->fetchOne('SELECT @ddrb_session'));
            self::assertNotNull($this->freshValue("SELECT IS_USED_LOCK('ddrb_qa_lock')"), 'GET_LOCK is still held.');
        }
    }

    /**
     * QA-DDRB-L2: PostgreSQL lock_timeout (SQLSTATE 55P03) is a lock wait timeout.
     */
    public function testPostgreSqlLockTimeoutIsRetried(): void
    {
        $this->boot('DDRB_POSTGRES_URL');
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 99 WHERE id = 1'));
        $this->connection->executeStatement("SET lock_timeout = '300ms'");
        $attempts = 0;

        $this->service(retryLockWaitTimeout: true)->retry(function () use (&$attempts): void {
            if (++$attempts === 2) {
                self::assertSame('OK', $this->otherSession()->execute('ROLLBACK'));
            }

            $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 5 WHERE id = 1');
        });

        self::assertSame(2, $attempts);
        self::assertSame(5, $this->committedValue(1));
    }

    /**
     * QA-DDRB-L2: MariaDB ≥ 11.6.2 with innodb_snapshot_isolation (the default) raises 1020 on a snapshot conflict,
     * its serialization failure.
     */
    public function testMariaDbSnapshotConflictIsRetried(): void
    {
        $this->boot('DDRB_MARIADB118_URL');
        $attempts = 0;

        $this->service()->retry(function () use (&$attempts): void {
            ++$attempts;
            $this->connection->transactional(function (Connection $connection) use ($attempts): void {
                $connection->fetchOne('SELECT counter_value FROM ddrb_counter WHERE id = 1');   // snapshot

                if ($attempts === 1) {
                    self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = counter_value + 100 WHERE id = 1'));
                }

                $connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
            });
        });

        self::assertSame(2, $attempts);
        self::assertSame(101, $this->committedValue(1));
    }
}
