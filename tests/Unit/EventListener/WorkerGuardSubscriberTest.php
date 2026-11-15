<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\WorkerGuardSubscriber;
use Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WorkerGuardSubscriberTest extends TestCase
{
    public function testSubscribesEarlyOnRequestAndLateOnTerminate(): void
    {
        self::assertSame(
            [
                KernelEvents::REQUEST   => ['onKernelRequest', 2048],
                KernelEvents::TERMINATE => ['onKernelTerminate', -2048],
            ],
            WorkerGuardSubscriber::getSubscribedEvents(),
        );
    }

    public function testRepairsOnMainRequestsAndTerminateOnly(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isAutoCommit')->willReturn(true);
        $connection->method('getTransactionNestingLevel')->willReturn(2);
        // Main request + terminate; the sub-request is skipped.
        $connection->expects($this->exactly(2))->method('close');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getConnectionNames')->willReturn(['default' => 'doctrine.dbal.default_connection']);
        $registry->method('getConnection')->willReturn($connection);

        $subscriber = new WorkerGuardSubscriber(new ConnectionGuard($registry, null, [], false));
        $kernel     = $this->createMock(HttpKernelInterface::class);

        $subscriber->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelRequest(new RequestEvent($kernel, Request::create('/_fragment'), HttpKernelInterface::SUB_REQUEST));
        $subscriber->onKernelTerminate(new TerminateEvent($kernel, Request::create('/'), new Response()));
    }
}
