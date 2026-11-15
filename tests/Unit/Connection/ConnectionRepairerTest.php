<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Connection;

use Doctrine\DBAL\Connection;
use Nowo\DoctrineDeadlockRetryBundle\Connection\ConnectionRepairer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConnectionRepairerTest extends TestCase
{
    public function testAfterFailureLeavesAConnectionAtTheEntryLevelAlone(): void
    {
        $connection = $this->connectionAt(1);
        $connection->expects($this->never())->method('close');
        $connection->expects($this->never())->method('rollBack');

        self::assertSame(ConnectionRepairer::ACTION_NONE, (new ConnectionRepairer())->afterFailure($connection, 1));
    }

    public function testAfterFailureAtEntryLevelZeroRollsBackFirst(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnOnConsecutiveCalls(1, 1);
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_ROLLED_BACK, (new ConnectionRepairer())->afterFailure($connection, 0));
    }

    public function testAfterFailureAtEntryLevelZeroClosesWhenTheSavepointRollbackFails(): void
    {
        $connection = $this->connectionAt(2);
        $connection->expects($this->once())->method('rollBack')->willThrowException(new RuntimeException('1305 SAVEPOINT DOCTRINE_2 does not exist'));
        $connection->expects($this->once())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer())->afterFailure($connection, 0));
    }

    public function testAfterFailureInsideACallersTransactionRollsBackToItsLevel(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnOnConsecutiveCalls(3, 3);
        $connection->expects($this->exactly(2))->method('rollBack');
        $connection->expects($this->never())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_ROLLED_BACK, (new ConnectionRepairer())->afterFailure($connection, 1));
    }

    public function testAfterFailureClosesWhenTheRollbackFails(): void
    {
        $connection = $this->connectionAt(2);
        $connection->method('rollBack')->willThrowException(new RuntimeException('1305 SAVEPOINT DOCTRINE_2 does not exist'));
        $connection->expects($this->once())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer())->afterFailure($connection, 1));
    }

    public function testBetweenRequestsLeavesACleanConnectionAlone(): void
    {
        $connection = $this->connectionAt(0);
        $connection->expects($this->never())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_NONE, (new ConnectionRepairer())->betweenRequests($connection));
    }

    public function testBetweenRequestsRollsBackASingleLevel(): void
    {
        $connection = $this->connectionAt(1);
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_ROLLED_BACK, (new ConnectionRepairer())->betweenRequests($connection));
    }

    public function testBetweenRequestsClosesWhenTheSingleLevelRollbackFails(): void
    {
        $connection = $this->connectionAt(1);
        $connection->method('rollBack')->willThrowException(new RuntimeException('gone away'));
        $connection->expects($this->once())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer())->betweenRequests($connection));
    }

    public function testBetweenRequestsClosesANestedLeftover(): void
    {
        $connection = $this->connectionAt(2);
        $connection->expects($this->never())->method('rollBack');
        $connection->expects($this->once())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer())->betweenRequests($connection));
    }

    public function testBetweenRequestsHonoursTheAutoCommitOffBaseline(): void
    {
        $connection = $this->connectionAt(2);
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_ROLLED_BACK, (new ConnectionRepairer())->betweenRequests($connection, 1));
    }

    private function connectionAt(int $level): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn($level);

        return $connection;
    }
}
