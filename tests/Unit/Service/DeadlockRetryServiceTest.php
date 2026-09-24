<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Exception;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\Exception\UnknownRetryProfileException;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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

    protected function setUp(): void
    {
        $this->connection    = $this->createMock(Connection::class);
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
        $deadlock = $this->createDeadlockException();

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('flush')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($deadlock),
                null,
            );

        $this->connection->method('isTransactionActive')->willReturn(true);
        $this->entityManager->expects($this->once())->method('rollback');

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

    public function testRetryDetectsDeadlockViaExceptionCode1213(): void
    {
        $attempts = 0;
        $service  = $this->createService(['default' => new RetryProfile('default', 1, 0)]);

        $result = $service->retry(static function () use (&$attempts): string {
            ++$attempts;

            if ($attempts === 1) {
                throw new RuntimeException('deadlock', 1213);
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
    }

    public function testRetryDetectsDeadlockInPreviousException(): void
    {
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

    public function testRetrySkipsRollbackWhenDisabled(): void
    {
        $this->stubOpenManager();
        $deadlock = $this->createDeadlockException();

        $this->connection->method('isTransactionActive')->willReturn(true);
        $this->entityManager->expects($this->never())->method('rollback');

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('flush')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($deadlock),
                null,
            );

        $service = $this->createService([
            'default' => new RetryProfile('default', 1, 0, rollbackOnDeadlock: false),
        ]);

        $service->flush();
    }

    public function testRetryThrowsWhenNoAttemptIsMade(): void
    {
        $service = $this->createService([
            'default' => new RetryProfile('default', -1, 0),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Deadlock retry loop ended without result');

        $service->retry(static fn (): string => 'unused');
    }

    public function testRetryWaitsAccordingToSleepMs(): void
    {
        $deadlock = $this->createDeadlockException();
        $attempts = 0;

        $service = $this->createService(['default' => new RetryProfile('default', 1, 1)]);

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
