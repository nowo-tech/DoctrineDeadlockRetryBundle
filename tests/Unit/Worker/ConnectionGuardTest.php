<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Worker;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

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

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getConnections')->willReturn(['clean' => $clean, 'leftover' => $leftover, 'poisoned' => $poisoned, 'no_auto' => $noAuto, 'odd' => new stdClass()]);
        $registry->method('getManagerNames')->willReturn(['default' => 'm1', 'archive' => 'm2', 'odm' => 'm3']);
        $registry->method('getManager')->willReturnMap([
            ['default', $open],
            ['archive', $closed],
            ['odm', $this->createMock(ObjectManager::class)],
        ]);
        $registry->expects($this->once())->method('resetManager')->with('archive');

        $logger = $this->createMock(LoggerInterface::class);
        $logged = [];
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

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getConnections')->willReturn(['main' => $guarded, 'legacy' => $ignored]);
        $registry->expects($this->never())->method('getManagerNames');

        self::assertSame(1, (new ConnectionGuard($registry, null, ['main'], false))->repair('kernel.terminate'));
    }

    private function connection(int $level, bool $autoCommit): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn($level);
        $connection->method('isAutoCommit')->willReturn($autoCommit);

        return $connection;
    }

    private function manager(bool $open): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn($open);

        return $manager;
    }
}
