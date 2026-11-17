<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Exception;
use LogicException;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\TransientFailureExceptionListener;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransientFailureExceptionListenerTest extends TestCase
{
    public function testSubscribesToKernelException(): void
    {
        self::assertSame([KernelEvents::EXCEPTION => ['onKernelException', -64]], TransientFailureExceptionListener::getSubscribedEvents());
    }

    public function testDeadlockBecomesA503HttpExceptionForTheHostRenderer(): void
    {
        $deadlock = $this->deadlock();
        $event    = $this->event($deadlock);

        (new TransientFailureExceptionListener(retryAfterSeconds: 4))->onKernelException($event);

        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(503, $mapped->getStatusCode());
        self::assertSame(['Retry-After' => '4'], $mapped->getHeaders());
        self::assertSame(TransientFailureExceptionListener::UNAVAILABLE_DETAIL, $mapped->getMessage());
        self::assertSame($deadlock, $mapped->getPrevious());
        self::assertNull($event->getResponse());
    }

    public function testProblemJsonFormatAnswersDirectlyWithoutLeakingSql(): void
    {
        $event = $this->event($this->deadlock());

        (new TransientFailureExceptionListener(format: TransientFailureExceptionListener::FORMAT_PROBLEM_JSON))->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Service Unavailable', 'status' => 503, 'detail' => TransientFailureExceptionListener::UNAVAILABLE_DETAIL],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testTransientStorageFailureHintWins(): void
    {
        $event = $this->event(new LogicException('wrapper', 0, new TransientStorageFailure(9)));
        (new TransientFailureExceptionListener(retryAfterSeconds: 1))->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(['Retry-After' => '9'], $mapped->getHeaders());

        $event = $this->event(new TransientStorageFailure());
        (new TransientFailureExceptionListener(retryAfterSeconds: 2))->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(['Retry-After' => '2'], $mapped->getHeaders(), 'No hint: the configured value.');
    }

    public function testFailuresInsideAHandlerFailedExceptionAreMapped(): void
    {
        $event = $this->event(new HandlerFailedException(new Envelope(new stdClass()), ['a' => new LogicException('bug'), 'b' => $this->deadlock()]));
        (new TransientFailureExceptionListener(retryAfterSeconds: 2))->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(503, $mapped->getStatusCode());
        self::assertSame(['Retry-After' => '2'], $mapped->getHeaders());

        $event = $this->event(new HandlerFailedException(new Envelope(new stdClass()), ['a' => new LogicException('bug'), 'b' => new TransientStorageFailure(8)]));
        (new TransientFailureExceptionListener(retryAfterSeconds: 2))->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(['Retry-After' => '8'], $mapped->getHeaders(), 'The hint of the wrapped TransientStorageFailure wins.');

        $event = $this->event(new HandlerFailedException(new Envelope(new stdClass()), ['a' => new LogicException('bug'), 'b' => new OptimisticLockException('stale', null)]));
        (new TransientFailureExceptionListener(conflictExceptions: [OptimisticLockException::class]))->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(409, $mapped->getStatusCode());

        $bug   = new HandlerFailedException(new Envelope(new stdClass()), ['a' => new LogicException('bug')]);
        $event = $this->event($bug);
        (new TransientFailureExceptionListener())->onKernelException($event);
        self::assertSame($bug, $event->getThrowable());
    }

    public function testConflictBecomes409(): void
    {
        $conflict = new LogicException('wrapper', 0, new OptimisticLockException('version mismatch', null));

        $event = $this->event($conflict);
        (new TransientFailureExceptionListener(conflictExceptions: [OptimisticLockException::class]))->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(409, $mapped->getStatusCode());
        self::assertSame(TransientFailureExceptionListener::CONFLICT_DETAIL, $mapped->getMessage());

        $event = $this->event($conflict);
        (new TransientFailureExceptionListener(conflictExceptions: [OptimisticLockException::class], format: TransientFailureExceptionListener::FORMAT_PROBLEM_JSON))->onKernelException($event);
        self::assertSame(409, $event->getResponse()?->getStatusCode());
        self::assertNull($event->getResponse()->headers->get('Retry-After'));
    }

    public function testOtherFailuresAndHttpExceptionsAreLeftAlone(): void
    {
        $other = new LogicException('bug');
        $event = $this->event($other);
        (new TransientFailureExceptionListener(conflictExceptions: [OptimisticLockException::class]))->onKernelException($event);
        self::assertSame($other, $event->getThrowable());

        $notFound = new NotFoundHttpException('missing', $this->deadlock());
        $event    = $this->event($notFound);
        (new TransientFailureExceptionListener())->onKernelException($event);
        self::assertSame($notFound, $event->getThrowable());
    }

    public function testScopeByPathOrRoutePrefix(): void
    {
        $listener = new TransientFailureExceptionListener(pathPrefixes: ['/api'], routePrefixes: ['admin_']);

        $event = $this->event($this->deadlock(), '/api/orders');
        $listener->onKernelException($event);
        self::assertInstanceOf(HttpExceptionInterface::class, $event->getThrowable());

        $event = $this->event($this->deadlock(), '/backoffice', 'admin_orders');
        $listener->onKernelException($event);
        self::assertInstanceOf(HttpExceptionInterface::class, $event->getThrowable());

        $event = $this->event($this->deadlock(), '/shop', 'shop_cart');
        $listener->onKernelException($event);
        self::assertInstanceOf(DeadlockException::class, $event->getThrowable());

        $event = $this->event($this->deadlock(), '/shop');
        $listener->onKernelException($event);
        self::assertInstanceOf(DeadlockException::class, $event->getThrowable(), 'No route attribute: not in scope.');

        $event = $this->event($this->deadlock(), '/apiary');
        $listener->onKernelException($event);
        self::assertInstanceOf(DeadlockException::class, $event->getThrowable(), 'Path prefixes match on segment boundaries.');

        $event = $this->event($this->deadlock(), '/api');
        $listener->onKernelException($event);
        self::assertInstanceOf(HttpExceptionInterface::class, $event->getThrowable());

        $event = $this->event($this->deadlock(), '/anything');
        (new TransientFailureExceptionListener(pathPrefixes: ['/']))->onKernelException($event);
        self::assertInstanceOf(HttpExceptionInterface::class, $event->getThrowable(), '"/" matches every path.');
    }

    public function testCommitFailedWrappingADriverErrorIsNotAConflict(): void
    {
        $driver = new class('server has gone away', 2006) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): string
            {
                return 'HY000';
            }
        };
        $commitFailed = new OptimisticLockException('Commit failed', null, new DriverException($driver, null));
        $event        = $this->event($commitFailed);

        (new TransientFailureExceptionListener(conflictExceptions: [OptimisticLockException::class]))->onKernelException($event);

        self::assertSame($commitFailed, $event->getThrowable(), 'Unknown commit outcome: neither 409 nor 503.');
    }

    public function testADriverLevelConflictClassStillMaps(): void
    {
        $driver = new class('Duplicate entry', 1062) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): string
            {
                return '23000';
            }
        };
        $event = $this->event(new UniqueConstraintViolationException($driver, null));

        (new TransientFailureExceptionListener(conflictExceptions: [UniqueConstraintViolationException::class]))->onKernelException($event);

        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(409, $mapped->getStatusCode());
    }

    private function event(Throwable $throwable, string $path = '/', ?string $route = null): ExceptionEvent
    {
        $request = Request::create($path);

        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
    }

    private function deadlock(): DeadlockException
    {
        $driver = new class('SQLSTATE[40001]: Deadlock found; UPDATE secret_table SET …', 1213) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): string
            {
                return '40001';
            }
        };

        return new DeadlockException($driver, null);
    }
}
