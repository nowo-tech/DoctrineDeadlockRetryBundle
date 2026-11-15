<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Worker;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\VarExporter\LazyObjectInterface;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConnectionGuardTest extends TestCase
{
    public function testRepairsLeftoverTransactionsAndClosedManagers(): void
    {
        $clean    = $this->connection(0, true);
        $leftover = $this->connection(1, true);
        $leftover->expects($this->once())->method('rollBack');
        $poisoned = $this->connection(2, true);
        $poisoned->expects($this->once())->method('close');
        $noAuto = $this->connection(1, false);
        $noAuto->expects($this->never())->method('rollBack');

        $open   = $this->manager(true);
        $closed = $this->manager(false);

        $registry = $this->registry(
            ['clean' => $clean, 'leftover' => $leftover, 'poisoned' => $poisoned, 'no_auto' => $noAuto, 'odd' => new stdClass()],
            ['default' => $open, 'archive' => $closed, 'odm' => $this->createMock(ObjectManager::class)],
        );
        $registry->expects($this->once())->method('resetManager')->with('archive');
        $open->expects($this->never())->method('clear');

        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
            $logged[] = $context;
        });

        self::assertSame(3, (new ConnectionGuard($registry, $logger))->repair('kernel.request'));
        self::assertSame(
            [
                ['connection' => 'leftover', 'nesting_level' => 1, 'action' => 'rolled_back', 'phase' => 'kernel.request'],
                ['connection' => 'poisoned', 'nesting_level' => 2, 'action' => 'closed', 'phase' => 'kernel.request'],
                ['manager' => 'archive', 'phase' => 'kernel.request'],
            ],
            $logged,
        );
    }

    public function testOnlyGuardsTheConfiguredConnectionsAndCanSkipManagers(): void
    {
        $guarded = $this->connection(2, true);
        $guarded->expects($this->once())->method('close');
        $ignored = $this->connection(2, true);
        $ignored->expects($this->never())->method('close');

        $registry = $this->registry(['main' => $guarded, 'legacy' => $ignored], []);
        $registry->expects($this->never())->method('getManagerNames');

        self::assertSame(1, (new ConnectionGuard($registry, null, ['main'], false))->repair('kernel.terminate'));
    }

    public function testClearsOpenManagersWhenAskedAndWarnsOnlyAboutPendingChanges(): void
    {
        $dirty = $this->manager(true, [new stdClass()]);
        $dirty->expects($this->once())->method('clear');
        $clean = $this->manager(true);
        $clean->expects($this->once())->method('clear');

        $registry = $this->registry([], ['dirty' => $dirty, 'clean' => $clean]);
        $logger   = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->isType('string'), ['manager' => 'dirty', 'phase' => 'kernel.terminate']);

        self::assertSame(1, (new ConnectionGuard($registry, $logger, clearManagers: true))->repair('kernel.terminate', true));
    }

    public function testDoesNotClearWhenNotConfiguredOrNotAsked(): void
    {
        $manager = $this->manager(true, [new stdClass()]);
        $manager->expects($this->never())->method('clear');
        $registry = $this->registry([], ['default' => $manager]);

        self::assertSame(0, (new ConnectionGuard($registry, null, [], false, clearManagers: false))->repair('kernel.terminate', true));
        self::assertSame(0, (new ConnectionGuard($registry, null, clearManagers: true))->repair('kernel.request'));
    }

    public function testSkipsServicesTheContainerHasNotInitialised(): void
    {
        $used = $this->connection(2, true);
        $used->expects($this->once())->method('close');

        $registry = $this->registry(['used' => $used, 'unused' => $this->connection(2, true)], ['default' => $this->manager(false), 'other' => $this->manager(false)]);
        $registry->expects($this->never())->method('resetManager');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('initialized')->willReturnCallback(static fn (string $id): bool => $id === 'conn.used');

        self::assertSame(1, (new ConnectionGuard($registry, null, container: $container))->repair('kernel.request'));
    }

    public function testSkipsAnUninitialisedLazyManager(): void
    {
        $lazy = $this->createMockForIntersectionOfInterfaces([EntityManagerInterface::class, LazyObjectInterface::class]);
        $lazy->method('isLazyObjectInitialized')->willReturn(false);
        $lazy->expects($this->never())->method('isOpen');
        $initialised = $this->createMockForIntersectionOfInterfaces([EntityManagerInterface::class, LazyObjectInterface::class]);
        $initialised->method('isLazyObjectInitialized')->willReturn(true);
        $initialised->method('isOpen')->willReturn(false);

        $registry = $this->registry([], ['lazy' => $lazy, 'initialised' => $initialised]);
        $registry->expects($this->once())->method('resetManager')->with('initialised');

        self::assertSame(1, (new ConnectionGuard($registry))->repair('kernel.request'));
    }

    /**
     * @param array<string, object> $connections
     * @param array<string, object> $managers
     */
    private function registry(array $connections, array $managers): ManagerRegistry&MockObject
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getConnectionNames')->willReturn(array_combine(array_keys($connections), array_map(static fn (string $name): string => 'conn.' . $name, array_keys($connections))));
        $registry->method('getConnection')->willReturnCallback(static fn (?string $name = null): object => $connections[(string) $name]);
        $registry->method('getManagerNames')->willReturn(array_combine(array_keys($managers), array_map(static fn (string $name): string => 'em.' . $name, array_keys($managers))));
        $registry->method('getManager')->willReturnCallback(static fn (?string $name = null): object => $managers[(string) $name]);

        return $registry;
    }

    private function connection(int $level, bool $autoCommit): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn($level);
        $connection->method('isAutoCommit')->willReturn($autoCommit);

        return $connection;
    }

    /**
     * @param list<object> $insertions
     */
    private function manager(bool $open, array $insertions = []): EntityManagerInterface&MockObject
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn($insertions);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn($open);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);

        return $manager;
    }
}
