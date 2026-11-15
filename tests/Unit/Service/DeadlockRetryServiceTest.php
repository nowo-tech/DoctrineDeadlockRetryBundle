<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Exception;
use LogicException;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\Exception\UnknownRetryProfileException;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use PDOException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DeadlockRetryServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private Connection&MockObject $connection;

    private int $resetCount = 0;

    /** @var list<string|null> */
    private array $requestedNames = [];

    private int $level = 0;

    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('isAutoCommit')->willReturn(true);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);
    }

    private function stubOpenManager(): void
    {
        $this->entityManager->method('isOpen')->willReturn(true);
    }

    public function testFlushSucceedsOnFirstAttempt(): void
    {
        $this->entityManager->expects($this->once())->method('flush');

        $service = $this->createService();
        $service->flush();
    }

    public function testFlushRetriesOnDeadlockThenSucceeds(): void
    {
        $this->stubOpenManager();
        $this->trackLevels();
        $deadlock = $this->createDeadlockException();

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('flush')
            ->willReturnCallback(function () use ($deadlock): void {
                if (++$this->flushes === 1) {
                    $this->level = 1;

                    throw $deadlock;
                }
            });

        // Entry level 0, a transaction left active after the failure: rolled back first, never closed when that works.
        $this->connection->expects($this->once())->method('rollBack')->willReturnCallback(function (): void {
            --$this->level;
        });
        $this->connection->expects($this->never())->method('close');

        $service = $this->createService(['default' => new RetryProfile('default', 1, 0)]);
        $service->flush();
    }

    public function testFlushUsesNamedProfile(): void
    {
        $this->stubOpenManager();
        $deadlock = $this->createDeadlockException();

        $this->entityManager
            ->expects($this->exactly(3))
            ->method('flush')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($deadlock),
                $this->throwException($deadlock),
                null,
            );

        $this->connection->method('isTransactionActive')->willReturn(false);

        $service = $this->createService([
            'default' => new RetryProfile('default', 1, 0),
            'heavy'   => new RetryProfile('heavy', 2, 0),
        ]);

        $service->flush('heavy');
    }

    public function testFlushThrowsAfterExhaustingRetries(): void
    {
        $this->stubOpenManager();
        $deadlock = $this->createDeadlockException();

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('flush')
            ->willThrowException($deadlock);

        $this->connection->method('isTransactionActive')->willReturn(false);

        $service = $this->createService(['default' => new RetryProfile('default', 1, 0)]);

        $this->expectException(DeadlockException::class);
        $service->flush();
    }

    public function testFlushWithUnknownProfileThrows(): void
    {
        $service = $this->createService();

        $this->expectException(UnknownRetryProfileException::class);
        $service->flush('missing');
    }

    public function testRetryRethrowsNonDeadlockExceptions(): void
    {
        $service = $this->createService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('other');

        $service->retry(static function (): never {
            throw new RuntimeException('other');
        });
    }

    public function testRetryReturnsCallableResult(): void
    {
        $service = $this->createService();

        $result = $service->retry(static fn (): string => 'ok');

        $this->assertSame('ok', $result);
    }

    public function testGetProfileNames(): void
    {
        $service = $this->createService([
            'default' => new RetryProfile('default', 3, 100),
            'fast'    => new RetryProfile('fast', 1, 10),
        ]);

        $this->assertSame(['default', 'fast'], $service->getProfileNames());
        $this->assertSame('default', $service->getDefaultProfileName());
    }

    public function testRetryDetectsDeadlockViaSqlState1213OnDriverException(): void
    {
        $this->stubOpenManager();
        $driverException = new class('lock', 1213) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): string
            {
                return 'HY000';
            }
        };

        $deadlock = new DriverException($driverException, null);
        $attempts = 0;

        $service = $this->createService(['default' => new RetryProfile('default', 1, 0)]);

        $result = $service->retry(static function () use ($deadlock, &$attempts): string {
            ++$attempts;

            if ($attempts === 1) {
                throw $deadlock;
            }

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(2, $attempts);
    }

    public function testRetryIgnoresCode1213OnNonDriverException(): void
    {
        $this->stubOpenManager();
        $attempts = 0;
        $service  = $this->createService(['default' => new RetryProfile('default', 3, 0)]);

        $caught = null;

        try {
            $service->retry(static function () use (&$attempts): never {
                ++$attempts;

                throw new RuntimeException('not a deadlock', 1213, new RuntimeException('cause', 1213));
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertSame('not a deadlock', $caught->getMessage());
        $this->assertSame(1, $attempts);
    }

    public function testRetryDetectsDeadlockViaPdoErrorInfo1213(): void
    {
        $this->stubOpenManager();
        $pdo            = new PDOException('SQLSTATE[HY000]: 1213 Deadlock');
        $pdo->errorInfo = ['HY000', 1213, 'Deadlock found when trying to get lock'];

        $this->assertSame('ok', $this->retryOnceAfter(new RuntimeException('wrapper', 0, $pdo)));
    }

    public function testRetryDetectsDeadlockViaPdoSqlState40001(): void
    {
        $this->stubOpenManager();
        $pdo = new PDOException('SQLSTATE[40001]: Serialization failure');
        (new ReflectionProperty(PDOException::class, 'code'))->setValue($pdo, '40001');

        $this->assertSame('ok', $this->retryOnceAfter($pdo));
    }

    public function testRetryRethrowsPdoExceptionThatIsNotADeadlock(): void
    {
        $this->stubOpenManager();
        $pdo = new PDOException('SQLSTATE[23000]: Integrity constraint violation');

        $this->expectExceptionObject($pdo);
        $this->retryOnceAfter($pdo);
    }

    private function retryOnceAfter(Throwable $first): string
    {
        $attempts = 0;
        $service  = $this->createService(['default' => new RetryProfile('default', 1, 0)]);

        return $service->retry(static function () use ($first, &$attempts): string {
            if (++$attempts === 1) {
                throw $first;
            }

            return 'ok';
        });
    }

    public function testRetryDetectsDeadlockInPreviousException(): void
    {
        $this->stubOpenManager();
        $deadlock = $this->createDeadlockException();
        $wrapped  = new RuntimeException('wrapper', 0, $deadlock);
        $attempts = 0;

        $service = $this->createService(['default' => new RetryProfile('default', 1, 0)]);

        $result = $service->retry(static function () use ($wrapped, &$attempts): string {
            ++$attempts;

            if ($attempts === 1) {
                throw $wrapped;
            }

            return 'recovered';
        });

        $this->assertSame('recovered', $result);
    }

    public function testNoRepairProfileDoesNotRetryOnALeftoverTransaction(): void
    {
        $this->stubOpenManager();
        $this->trackLevels();
        $deadlock = $this->createDeadlockException();
        $attempts = 0;

        $this->connection->expects($this->never())->method('close');
        $this->connection->expects($this->never())->method('rollBack');

        $service = $this->createService(['default' => new RetryProfile('default', 3, 0, rollbackOnDeadlock: false)]);
        $caught  = null;

        try {
            $service->retry(function () use ($deadlock, &$attempts): never {
                ++$attempts;
                $this->level = 2;

                throw $deadlock;
            });
        } catch (DeadlockException $exception) {
            $caught = $exception;
        }

        $this->assertSame($deadlock, $caught);
        $this->assertSame(1, $attempts, 'No replay inside ghost savepoints.');
        $this->assertSame(2, $this->level, 'The connection is left as it is.');
    }

    public function testNoRepairProfileStillRetriesWhenNothingIsLeftOpen(): void
    {
        $this->stubOpenManager();
        $this->trackLevels();
        $deadlock = $this->createDeadlockException();
        $attempts = 0;

        $service = $this->createService(['default' => new RetryProfile('default', 1, 0, rollbackOnDeadlock: false)]);
        $service->retry(static function () use ($deadlock, &$attempts): void {
            if (++$attempts === 1) {
                throw $deadlock;
            }
        });

        $this->assertSame(2, $attempts);
    }

    public function testRetryAlwaysMakesAtLeastOneAttempt(): void
    {
        $service = $this->createService([
            'default' => new RetryProfile('default', -1, 0),
        ]);

        $this->assertSame('ran', $service->retry(static fn (): string => 'ran'));
    }

    public function testRetryWaitsAccordingToSleepMs(): void
    {
        $this->stubOpenManager();
        $deadlock = $this->createDeadlockException();
        $attempts = 0;

        $service = $this->createService(['default' => new RetryProfile('default', 1, 1, fullJitter: false)]);

        $service->retry(static function () use ($deadlock, &$attempts): void {
            ++$attempts;

            if ($attempts === 1) {
                throw $deadlock;
            }
        });

        $this->assertSame(2, $attempts);
    }

    public function testFlushResetsClosedManagerAndRethrowsDeadlock(): void
    {
        $deadlock = $this->createDeadlockException();
        $closed   = $this->createClosingManager($deadlock);
        $fresh    = $this->createOpenManager();
        $registry = $this->createRegistry($closed, $fresh);

        $service = new DeadlockRetryService($this->entityManager, ['default' => new RetryProfile('default', 3, 0)], 'default', $registry);

        try {
            $service->flush();
            self::fail('The deadlock must be rethrown when the flush cannot be replayed.');
        } catch (DeadlockException $exception) {
            self::assertSame($deadlock, $exception);
        }

        self::assertSame(1, $this->resetCount);
        self::assertSame($fresh, $service->getEntityManager());
    }

    public function testRetryResetsClosedManagerBeforeNextAttempt(): void
    {
        $deadlock = $this->createDeadlockException();
        $closed   = $this->createClosingManager($deadlock);
        $fresh    = $this->createOpenManager();
        $fresh->expects($this->once())->method('flush');
        $registry = $this->createRegistry($closed, $fresh);

        $service  = new DeadlockRetryService($this->entityManager, ['default' => new RetryProfile('default', 3, 0)], 'default', $registry, 'orders');
        $managers = [];

        $result = $service->retry(static function () use ($service, &$managers): string {
            $managers[] = $manager = $service->getEntityManager();
            $manager->flush();

            return 'saved';
        });

        self::assertSame('saved', $result);
        self::assertSame([$closed, $fresh], $managers);
        self::assertSame(1, $this->resetCount);
        self::assertSame(['orders'], array_unique($this->requestedNames));
    }

    public function testClosedManagerDoesNotLeakIntoNextRequestWithoutKernelReset(): void
    {
        $closed = $this->createClosingManager(new RuntimeException('constraint violation'));
        $fresh  = $this->createOpenManager();
        $fresh->expects($this->once())->method('flush');
        $registry = $this->createRegistry($closed, $fresh);
        $service  = new DeadlockRetryService($this->entityManager, ['default' => new RetryProfile('default', 3, 0)], 'default', $registry);

        // Request 1: a non-deadlock failure closes the manager
        try {
            $service->flush();
            self::fail('The original exception must be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('constraint violation', $exception->getMessage());
        }

        // Request 2: same service instance, no services_resetter
        $service->flush();

        self::assertSame(1, $this->resetCount);
    }

    public function testFlushWithoutRegistryRethrowsDeadlockWhenManagerIsClosed(): void
    {
        $deadlock = $this->createDeadlockException();
        $this->entityManager->method('isOpen')->willReturn(false);
        $this->entityManager->expects($this->once())->method('flush')->willThrowException($deadlock);

        $service = $this->createService(['default' => new RetryProfile('default', 3, 0)]);

        $this->expectExceptionObject($deadlock);
        $service->flush();
    }

    public function testRetryWithoutRegistryRethrowsOriginalDeadlockWhenManagerIsClosed(): void
    {
        $deadlock = $this->createDeadlockException();
        $this->entityManager->method('isOpen')->willReturn(false);
        $service  = $this->createService(['default' => new RetryProfile('default', 3, 0)]);
        $attempts = 0;

        try {
            $service->retry(static function () use (&$attempts, $deadlock): string {
                if (++$attempts === 1) {
                    throw $deadlock;
                }

                return 'replayed on a closed manager';
            });
            self::fail('The deadlock must be rethrown.');
        } catch (DeadlockException $exception) {
            self::assertSame($deadlock, $exception, 'The original deadlock must not be hidden by a closed-manager error.');
        }

        self::assertSame(1, $attempts);
    }

    public function testGetEntityManagerWithoutRegistryReturnsInjectedManager(): void
    {
        $this->stubOpenManager();

        self::assertSame($this->entityManager, $this->createService()->getEntityManager());
    }

    public function testGetEntityManagerFallsBackWhenRegistryManagerIsNotAnEntityManager(): void
    {
        $this->stubOpenManager();
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createMock(ObjectManager::class));
        $registry->expects($this->never())->method('resetManager');

        $service = new DeadlockRetryService($this->entityManager, ['default' => new RetryProfile('default', 3, 0)], 'default', $registry);

        self::assertSame($this->entityManager, $service->getEntityManager());
    }

    private function createRegistry(EntityManagerInterface $current, EntityManagerInterface $afterReset): ManagerRegistry&MockObject
    {
        $this->resetCount     = 0;
        $this->requestedNames = [];
        $registry             = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(function (?string $name = null) use ($current, $afterReset): EntityManagerInterface {
            $this->requestedNames[] = $name;

            return $this->resetCount > 0 ? $afterReset : $current;
        });
        $registry->method('resetManager')->willReturnCallback(function (?string $name = null) use ($afterReset): EntityManagerInterface {
            ++$this->resetCount;

            return $afterReset;
        });

        return $registry;
    }

    private function createClosingManager(Throwable $failure): EntityManagerInterface&MockObject
    {
        $open    = true;
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($this->connection);
        $manager->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $manager->expects($this->once())->method('flush')->willReturnCallback(static function () use (&$open, $failure): never {
            $open = false;

            throw $failure;
        });

        return $manager;
    }

    private function createOpenManager(): EntityManagerInterface&MockObject
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($this->connection);
        $manager->method('isOpen')->willReturn(true);

        return $manager;
    }

    private function createDeadlockException(): DeadlockException
    {
        $driverException = new class('Deadlock found when trying to get lock', 1213) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): string
            {
                return '40001';
            }
        };

        return new DeadlockException($driverException, null);
    }

    public function testLockWaitTimeoutIsRetriedWhenTheProfileAllowsIt(): void
    {
        $this->stubOpenManager();
        $attempts = 0;
        $timeout  = new LockWaitTimeoutException($this->driverError(1205, 'HY000'), null);

        $result = $this->createService(['default' => new RetryProfile('default', 3, 0, retryLockWaitTimeout: true)])->retry(static function () use ($timeout, &$attempts): string {
            if (++$attempts === 1) {
                throw $timeout;
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(2, $attempts);
    }

    public function testLockWaitTimeoutIsNotRetriedByDefault(): void
    {
        $this->stubOpenManager();
        $attempts = 0;
        $timeout  = new LockWaitTimeoutException($this->driverError(1205, 'HY000'), null);
        $service  = $this->createService(['default' => new RetryProfile('default', 3, 0)]);

        $caught = null;

        try {
            $service->retry(static function () use ($timeout, &$attempts): never {
                ++$attempts;

                throw $timeout;
            });
        } catch (LockWaitTimeoutException $exception) {
            $caught = $exception;
        }

        $this->assertSame($timeout, $caught);

        $this->assertSame(1, $attempts);
    }

    public function testLostSavepointWithoutTheDeadlockInTheChainIsNotRetried(): void
    {
        $this->stubOpenManager();
        $attempts = 0;
        $lost     = new DriverException($this->driverError(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist'), null);
        $caught   = null;

        try {
            $this->createService()->retry(static function () use ($lost, &$attempts): never {
                ++$attempts;

                throw $lost;
            });
        } catch (DriverException $exception) {
            $caught = $exception;
        }

        $this->assertSame($lost, $caught);
        $this->assertSame(1, $attempts);
    }

    public function testNothingIsRetriedInsideACallersTransaction(): void
    {
        $this->stubOpenManager();
        $this->trackLevels(1);
        $deadlock = $this->createDeadlockException();
        $attempts = 0;

        // Entry level 1 (the caller's transaction), level 2 left after the failure: rolled back to 1, not retried.
        $this->connection->expects($this->once())->method('rollBack')->willReturnCallback(function (): void {
            --$this->level;
        });
        $this->connection->expects($this->never())->method('close');

        $caught = null;

        try {
            $this->createService()->retry(function () use ($deadlock, &$attempts): never {
                ++$attempts;
                $this->level = 2;

                throw $deadlock;
            });
        } catch (DeadlockException $exception) {
            $caught = $exception;
        }

        $this->assertSame($deadlock, $caught);
        $this->assertSame(1, $attempts);
        $this->assertSame(1, $this->level);
    }

    public function testNonTransientFailureAlsoRepairsTheConnection(): void
    {
        $this->stubOpenManager();
        $this->trackLevels();
        $this->connection->expects($this->once())->method('rollBack')->willReturnCallback(function (): void {
            --$this->level;
        });
        $this->connection->expects($this->never())->method('close');

        $this->expectException(RuntimeException::class);

        $this->createService()->retry(function (): never {
            $this->level = 1;

            throw new RuntimeException('bug');
        });
    }

    public function testRetriesAndRepairsAreLoggedWithoutSql(): void
    {
        $this->stubOpenManager();
        $this->trackLevels();
        $deadlock = $this->createDeadlockException();
        $this->connection->method('rollBack')->willThrowException(new RuntimeException('1305 SAVEPOINT DOCTRINE_2 does not exist'));
        $this->connection->expects($this->once())->method('close')->willReturnCallback(function (): void {
            $this->level = 0;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('notice')->with(
            $this->isType('string'),
            ['nesting_level' => 2, 'entry_level' => 0, 'action' => 'closed'],
        );
        $logger->expects($this->once())->method('info')->with(
            $this->isType('string'),
            ['kind' => 'deadlock', 'profile' => 'default', 'attempt' => 1, 'delay_ms' => 0],
        );

        $attempts = 0;
        $service  = new DeadlockRetryService($this->entityManager, ['default' => new RetryProfile('default', 1, 0)], 'default', null, null, $logger);
        $service->retry(function () use ($deadlock, &$attempts): void {
            if (++$attempts === 1) {
                $this->level = 2;

                throw $deadlock;
            }
        });

        $this->assertSame(2, $attempts);
    }

    public function testRetryClearsAnOpenManagerBeforeReplaying(): void
    {
        $this->stubOpenManager();
        $deadlock = $this->createDeadlockException();
        $this->entityManager->expects($this->once())->method('clear');
        $attempts = 0;

        $this->createService()->retry(static function () use ($deadlock, &$attempts): void {
            if (++$attempts === 1) {
                throw $deadlock;
            }
        });

        $this->assertSame(2, $attempts);
    }

    public function testTimeBudgetStopsRetries(): void
    {
        $this->stubOpenManager();
        $deadlock = $this->createDeadlockException();
        $attempts = 0;
        $caught   = null;

        try {
            $this->createService(['default' => new RetryProfile('default', 3, 0, maxTotalMs: 0)])->retry(static function () use ($deadlock, &$attempts): never {
                ++$attempts;

                throw $deadlock;
            });
        } catch (DeadlockException $exception) {
            $caught = $exception;
        }

        $this->assertSame($deadlock, $caught);
        $this->assertSame(1, $attempts);
    }

    public function testExhaustionRethrowsTheDeadlockTheOrmWrapped(): void
    {
        $this->stubOpenManager();
        $deadlock = $this->createDeadlockException();
        $lost     = new DriverException($this->driverError(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', $deadlock), null);
        $caught   = null;

        try {
            $this->createService(['default' => new RetryProfile('default', 0, 0)])->retry(static function () use ($lost): never {
                throw $lost;
            });
        } catch (DeadlockException $exception) {
            $caught = $exception;
        }

        $this->assertSame($deadlock, $caught);
    }

    public function testExhaustionKeepsTheApplicationsOwnWrapper(): void
    {
        $this->stubOpenManager();
        $wrapper = new LogicException('payment failed', 0, $this->createDeadlockException());

        $this->expectExceptionObject($wrapper);

        $this->createService(['default' => new RetryProfile('default', 0, 0)])->retry(static function () use ($wrapper): never {
            throw $wrapper;
        });
    }

    public function testAutoCommitOffConnectionIsRetriedAfterRollingBackTheImplicitTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isAutoCommit')->willReturn(false);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->once())->method('rollBack');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn(true);
        $timeout  = new LockWaitTimeoutException($this->driverError(1205, 'HY000'), null);
        $attempts = 0;

        $service = new DeadlockRetryService($manager, ['default' => new RetryProfile('default', 1, 0, retryLockWaitTimeout: true)], 'default');
        $service->retry(static function () use ($timeout, &$attempts): void {
            if (++$attempts === 1) {
                throw $timeout;
            }
        });

        $this->assertSame(2, $attempts);
    }

    public function testAutoCommitOffImplicitTransactionIsClosedWhenItsRollbackFails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isAutoCommit')->willReturn(false);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('rollBack')->willThrowException(new RuntimeException('gone'));
        $connection->expects($this->once())->method('close');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn(true);
        $attempts = 0;
        $deadlock = $this->createDeadlockException();

        (new DeadlockRetryService($manager, ['default' => new RetryProfile('default', 1, 0)], 'default'))->retry(static function () use ($deadlock, &$attempts): void {
            if (++$attempts === 1) {
                throw $deadlock;
            }
        });

        $this->assertSame(2, $attempts);
    }

    public function testAutoCommitOffInsideACallersTransactionIsNotRetried(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isAutoCommit')->willReturn(false);
        $connection->method('getTransactionNestingLevel')->willReturn(2);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn(true);
        $deadlock = $this->createDeadlockException();

        $this->expectExceptionObject($deadlock);

        (new DeadlockRetryService($manager, ['default' => new RetryProfile('default', 1, 0)], 'default'))->retry(static function () use ($deadlock): never {
            throw $deadlock;
        });
    }

    private function trackLevels(int $entryLevel = 0): void
    {
        $this->level = $entryLevel;
        $this->connection->method('getTransactionNestingLevel')->willReturnCallback(fn (): int => $this->level);
    }

    private function driverError(int $code, string $sqlState, string $message = 'driver error', ?Throwable $previous = null): DriverExceptionInterface
    {
        return new class($message, $code, $sqlState, $previous) extends Exception implements DriverExceptionInterface {
            public function __construct(string $message, int $code, private readonly string $sqlState, ?Throwable $previous)
            {
                parent::__construct($message, $code, $previous);
            }

            public function getSQLState(): string
            {
                return $this->sqlState;
            }
        };
    }

    /**
     * @param array<string, RetryProfile> $profiles
     */
    private function createService(array $profiles = []): DeadlockRetryService
    {
        if ($profiles === []) {
            $profiles = ['default' => new RetryProfile('default', 3, 0)];
        }

        return new DeadlockRetryService($this->entityManager, $profiles, 'default');
    }
}
