<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database;

use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\TransientFailureExceptionListener;
use Nowo\DoctrineDeadlockRetryBundle\Http\HasErrorCodeInterface;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\Counter;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * A real lock wait timeout (1205) on MySQL / MariaDB, retried; and the 409 + Retry-After + CONFLICT_001 once retries are exhausted.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LockWaitTimeoutTest extends DatabaseTestCase
{
    #[DataProvider('mysqlFamily')]
    public function testLockWaitTimeoutIsRetried(string $server): void
    {
        $this->bootWithHeldRow($server);
        $service  = $this->service();
        $attempts = 0;

        $service->retry(function () use ($service, &$attempts): void {
            ++$attempts;

            if ($attempts === 2) {
                self::assertSame('OK', $this->otherSession()->execute('ROLLBACK'));
            }

            $manager = $service->getEntityManager();
            $counter = $manager->find(Counter::class, 1);
            self::assertInstanceOf(Counter::class, $counter);
            $counter->value = 7;
            $manager->flush();
        });

        self::assertSame(2, $attempts);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(7, $this->committedValue(1));
    }

    #[DataProvider('mysqlFamily')]
    public function testExhaustedLockWaitMapsTo409WithRetryAfter(string $server): void
    {
        $this->bootWithHeldRow($server);
        $service = $this->service(maxRetries: 1);
        $failure = null;

        try {
            $service->retry(static function () use ($service): void {
                $manager = $service->getEntityManager();
                $counter = $manager->find(Counter::class, 1);
                self::assertInstanceOf(Counter::class, $counter);
                $counter->value = 7;
                $manager->flush();
            });
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        self::assertInstanceOf(LockWaitTimeoutException::class, $failure);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());

        $listener = new TransientFailureExceptionListener(retryAfterSeconds: 2);
        $event    = new ExceptionEvent($this->createMock(HttpKernelInterface::class), Request::create('/api/orders'), HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onKernelException($event);

        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(409, $mapped->getStatusCode());
        self::assertSame(['Retry-After' => '2'], $mapped->getHeaders());
        self::assertSame('CONFLICT_001', $mapped instanceof HasErrorCodeInterface ? $mapped->errorCode() : null);
        self::assertStringNotContainsStringIgnoringCase('ddrb_counter', $mapped->getMessage());
    }

    private function bootWithHeldRow(string $server): void
    {
        $this->boot($server);
        $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 99 WHERE id = 1'));
    }
}
