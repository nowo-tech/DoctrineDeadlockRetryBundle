<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Exception;
use InvalidArgumentException;
use LogicException;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\TransientFailureExceptionListener;
use Nowo\DoctrineDeadlockRetryBundle\Http\ConflictResponseException;
use Nowo\DoctrineDeadlockRetryBundle\Http\UnavailableResponseException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureKind as TransactionKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
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

    public function testDeadlockBecomesA409ConflictWithRetryAfterAndConflict001(): void
    {
        $deadlock = $this->deadlock();
        $event    = $this->event($deadlock);

        (new TransientFailureExceptionListener(retryAfterSeconds: 4))->onKernelException($event);

        $mapped = $event->getThrowable();
        self::assertInstanceOf(ConflictResponseException::class, $mapped);
        self::assertSame(409, $mapped->getStatusCode());
        self::assertSame('CONFLICT_001', $mapped->errorCode());
        self::assertSame(['Retry-After' => '4'], $mapped->getHeaders());
        self::assertSame(TransientFailureExceptionListener::CONFLICT_DETAIL, $mapped->getMessage());
        self::assertSame($deadlock, $mapped->getPrevious());
        self::assertNull($event->getResponse());
    }

    public function testConflictStatus503KeepsTheOldAnswerWithTheCode(): void
    {
        $deadlock = $this->deadlock();
        $event    = $this->event($deadlock);

        (new TransientFailureExceptionListener(retryAfterSeconds: 4, conflictStatus: 503))->onKernelException($event);

        $mapped = $event->getThrowable();
        self::assertInstanceOf(UnavailableResponseException::class, $mapped);
        self::assertSame(503, $mapped->getStatusCode());
        self::assertSame('CONFLICT_001', $mapped->errorCode());
        self::assertSame(['Retry-After' => 4], $mapped->getHeaders());
        self::assertSame(TransientFailureExceptionListener::UNAVAILABLE_DETAIL, $mapped->getMessage());
        self::assertSame($deadlock, $mapped->getPrevious());
    }

    public function testOnly409And503AreValidConflictStatuses(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TransientFailureExceptionListener(conflictStatus: 500);
    }

    public function testCommittedConflictAnswers409Conflict002WithoutRetryAfter(): void
    {
        $committed = new ConcurrencyConflict(3, TransactionKind::Deadlock, 5, $this->deadlock(), true);

        $event = $this->event($committed);
        (new TransientFailureExceptionListener(retryAfterSeconds: 4))->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(ConflictResponseException::class, $mapped);
        self::assertSame(409, $mapped->getStatusCode());
        self::assertSame('CONFLICT_002', $mapped->errorCode());
        self::assertSame([], $mapped->getHeaders(), 'Nothing is retry-safe: no Retry-After.');
        self::assertSame(TransientFailureExceptionListener::COMMITTED_DETAIL, $mapped->getMessage());

        $event = $this->event($committed);
        (new TransientFailureExceptionListener(conflictStatus: 503))->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(UnavailableResponseException::class, $mapped);
        self::assertSame('CONFLICT_002', $mapped->errorCode());
        self::assertSame([], $mapped->getHeaders());

        $event = $this->event($committed);
        (new TransientFailureExceptionListener(format: TransientFailureExceptionListener::FORMAT_PROBLEM_JSON))->onKernelException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertNull($response->headers->get('Retry-After'));
        self::assertSame('CONFLICT_002', json_decode((string) $response->getContent(), true)['code']);
    }

    public function testALostSavepointAfterAFailedCommitAnswers409Conflict002(): void
    {
        $driver = new class('SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT DOCTRINE_2 does not exist', 1305) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): string
            {
                return '42000';
            }
        };
        $event = $this->event(new DriverException($driver, null));

        (new TransientFailureExceptionListener())->onKernelException($event);

        $mapped = $event->getThrowable();
        self::assertInstanceOf(ConflictResponseException::class, $mapped);
        self::assertSame('CONFLICT_002', $mapped->errorCode());
        self::assertSame([], $mapped->getHeaders());
    }

    public function testUniqueViolationAndLostConnectionAreNotConflicts(): void
    {
        foreach ([new UniqueConstraintViolationException($this->driverError(1062, '23000'), null), new DriverException($this->driverError(2006, 'HY000'), null)] as $failure) {
            $event = $this->event($failure);
            (new TransientFailureExceptionListener())->onKernelException($event);

            self::assertSame($failure, $event->getThrowable(), 'Never CONFLICT_*: a real error, or the host\'s own domain answer.');
            self::assertNull($event->getResponse());
        }
    }

    public function testProblemJsonFormatAnswersDirectlyWithoutLeakingSql(): void
    {
        $event = $this->event($this->deadlock());

        (new TransientFailureExceptionListener(format: TransientFailureExceptionListener::FORMAT_PROBLEM_JSON))->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Conflict', 'status' => 409, 'detail' => TransientFailureExceptionListener::CONFLICT_DETAIL, 'code' => 'CONFLICT_001'],
            json_decode((string) $response->getContent(), true),
        );

        $event = $this->event($this->deadlock());
        (new TransientFailureExceptionListener(format: TransientFailureExceptionListener::FORMAT_PROBLEM_JSON, conflictStatus: 503))->onKernelException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Service Unavailable', 'status' => 503, 'detail' => TransientFailureExceptionListener::UNAVAILABLE_DETAIL, 'code' => 'CONFLICT_001'],
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
        $event = $this->event(new HandlerFailedException(new Envelope(new stdClass()), ['b' => $this->deadlock()]));
        (new TransientFailureExceptionListener(retryAfterSeconds: 2))->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(409, $mapped->getStatusCode());
        self::assertSame(['Retry-After' => '2'], $mapped->getHeaders());

        $event = $this->event(new HandlerFailedException(new Envelope(new stdClass()), ['a' => new LogicException('bug'), 'b' => new TransientStorageFailure(8)]));
        (new TransientFailureExceptionListener(retryAfterSeconds: 2))->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(['Retry-After' => '8'], $mapped->getHeaders(), 'Any TransientStorageFailure answers 409 with its hint, as in 3.0.');

        $event = $this->event(new HandlerFailedException(new Envelope(new stdClass()), ['a' => new OptimisticLockException('stale', null), 'b' => new UniqueConstraintViolationException($this->driverError(1062, '23000'), null)]));
        (new TransientFailureExceptionListener(conflictExceptions: [OptimisticLockException::class, UniqueConstraintViolationException::class]))->onKernelException($event);
        $mapped = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $mapped);
        self::assertSame(409, $mapped->getStatusCode(), 'Every wrapped exception is a conflict.');

        $bug   = new HandlerFailedException(new Envelope(new stdClass()), ['a' => new LogicException('bug')]);
        $event = $this->event($bug);
        (new TransientFailureExceptionListener())->onKernelException($event);
        self::assertSame($bug, $event->getThrowable());
    }

    public function testADomainErrorNextToADeadlockOrAConflictStaysA500(): void
    {
        foreach ([['audit' => new LogicException('bug'), 'pay' => $this->deadlock()], ['pay' => $this->deadlock(), 'audit' => new LogicException('bug')], ['a' => new OptimisticLockException('stale', null), 'b' => new LogicException('bug')]] as $exceptions) {
            $failed = new HandlerFailedException(new Envelope(new stdClass()), $exceptions);
            $event  = $this->event($failed);
            (new TransientFailureExceptionListener(conflictExceptions: [OptimisticLockException::class]))->onKernelException($event);
            self::assertSame($failed, $event->getThrowable(), 'Left to the host: a 500.');
            self::assertNull($event->getResponse());
        }
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
        self::assertInstanceOf(ConflictResponseException::class, $mapped);
        self::assertSame('RESOURCE_CHANGED', $mapped->errorCode(), 'A domain code, never CONFLICT_*.');

        $event = $this->event($conflict);
        (new TransientFailureExceptionListener(conflictExceptions: [OptimisticLockException::class], format: TransientFailureExceptionListener::FORMAT_PROBLEM_JSON))->onKernelException($event);
        self::assertSame(409, $event->getResponse()?->getStatusCode());
        self::assertNull($event->getResponse()->headers->get('Retry-After'));
        self::assertSame('RESOURCE_CHANGED', json_decode((string) $event->getResponse()->getContent(), true)['code']);
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

    private function driverError(int $code, string $sqlState): DriverExceptionInterface
    {
        return new class('driver error', $code, $sqlState) extends Exception implements DriverExceptionInterface {
            public function __construct(string $message, int $code, private readonly string $sqlState)
            {
                parent::__construct($message, $code);
            }

            public function getSQLState(): string
            {
                return $this->sqlState;
            }
        };
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
