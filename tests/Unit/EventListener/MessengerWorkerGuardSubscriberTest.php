<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\MessengerWorkerGuardSubscriber;
use Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class MessengerWorkerGuardSubscriberTest extends TestCase
{
    public function testSubscribesToHandledAndFailedMessages(): void
    {
        self::assertSame(
            [
                WorkerMessageHandledEvent::class => ['afterMessage', -2048],
                WorkerMessageFailedEvent::class  => ['afterMessage', -2048],
            ],
            MessengerWorkerGuardSubscriber::getSubscribedEvents(),
        );
    }

    public function testRepairsAndClearsAfterEachMessage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isAutoCommit')->willReturn(true);
        $connection->method('getTransactionNestingLevel')->willReturn(2);
        $connection->expects($this->once())->method('close');

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn([]);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->expects($this->once())->method('clear');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getConnectionNames')->willReturn(['default' => 'c']);
        $registry->method('getConnection')->willReturn($connection);
        $registry->method('getManagerNames')->willReturn(['default' => 'm']);
        $registry->method('getManager')->willReturn($manager);

        (new MessengerWorkerGuardSubscriber(new ConnectionGuard($registry, clearManagers: true)))->afterMessage();
    }
}
