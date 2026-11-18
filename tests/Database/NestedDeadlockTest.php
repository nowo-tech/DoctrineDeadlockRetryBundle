<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\TransientFailureExceptionListener;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\Counter;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * A real deadlock (1213) inside a nested transaction on MySQL / MariaDB: the server drops every savepoint, the ORM's
 * ROLLBACK TO SAVEPOINT fails with 1305 and the connection would stay at nesting level 2.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NestedDeadlockTest extends DatabaseTestCase
{
    #[DataProvider('mysqlFamily')]
    public function testDeadlockInNestedFlushIsRetriedAndLeavesTheConnectionClean(string $server): void
    {
        $this->boot($server);
        $service  = $this->service();
        $attempts = 0;

        $result = $service->retry(function () use ($service, &$attempts): string {
            ++$attempts;
            $attempt = $attempts;

            if ($attempt === 2) {
                // The peer got row 1 once the victim was rolled back; let it commit before replaying.
                self::assertSame('OK', $this->otherSession()->await());
                self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
            }

            return $service->getEntityManager()->wrapInTransaction(function (EntityManagerInterface $manager) use ($attempt): string {
                $this->deadlockingNestedFlush($manager, $attempt === 1);

                return 'done';
            });
        });

        self::assertSame('done', $result);
        self::assertSame(2, $attempts);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame(101, $this->committedValue(1));
        self::assertSame(101, $this->committedValue(2));

        // The next "request" served by the same worker connection commits for real.
        $manager = $service->getEntityManager();
        $counter = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $counter);
        $counter->value = 500;
        $manager->flush();

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(500, $this->committedValue(1));
    }

    #[DataProvider('mysqlFamily')]
    public function testExhaustedNestedDeadlockMapsTo409WithRetryAfter(string $server): void
    {
        $this->boot($server);
        $service = $this->service(maxRetries: 0);
        $failure = null;

        try {
            $service->retry(function () use ($service): void {
                $service->getEntityManager()->wrapInTransaction(function (EntityManagerInterface $manager): void {
                    $this->deadlockingNestedFlush($manager, true);
                });
            });
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        // QA-DDRB-L3: the deadlock itself escapes, not the ORM's 1305 that carries it.
        self::assertInstanceOf(DeadlockException::class, $failure);
        self::assertSame('OK', $this->otherSession()->await());
        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        self::assertSame(0, $this->connection->getTransactionNestingLevel(), 'The connection is repaired even when retries are exhausted.');

        $listener = new TransientFailureExceptionListener(retryAfterSeconds: 3, format: TransientFailureExceptionListener::FORMAT_PROBLEM_JSON);
        $event    = new ExceptionEvent($this->createMock(HttpKernelInterface::class), Request::create('/orders'), HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('"code":"CONFLICT_001"', (string) $response->getContent());
        self::assertSame('3', $response->headers->get('Retry-After'));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertStringNotContainsStringIgnoringCase('ddrb_counter', (string) $response->getContent());
        self::assertStringNotContainsStringIgnoringCase('SAVEPOINT', (string) $response->getContent());
    }

    private function deadlockingNestedFlush(EntityManagerInterface $manager, bool $withPeer): void
    {
        $manager->getConnection()->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');

        if ($withPeer) {
            $this->startDeadlockingPeer();
        }

        $counter = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $counter);
        ++$counter->value;

        // Nested: the unit of work opens SAVEPOINT DOCTRINE_2; with the peer waiting on row 1 this UPDATE deadlocks.
        $manager->flush();
    }
}
