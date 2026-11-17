<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Failure;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\RetryableException;
use Exception;
use LogicException;
use Nowo\DoctrineDeadlockRetryBundle\Failure\ThrowableChain;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureClassifier;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureKind;
use Nowo\TransactionContract\TransientStorageFailure;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransientFailureClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, TransientFailureKind|null}>
     */
    public static function failures(): iterable
    {
        yield 'MySQL deadlock 1213' => [new DeadlockException(self::driver(1213, '40001'), null), TransientFailureKind::Deadlock];
        yield 'PostgreSQL deadlock 40P01' => [new DeadlockException(self::driver(7, '40P01'), null), TransientFailureKind::Deadlock];
        yield 'PostgreSQL serialization 40001' => [new DeadlockException(self::driver(7, '40001'), null), TransientFailureKind::SerializationFailure];
        yield 'DeadlockException without codes' => [new DeadlockException(self::driver(0, null), null), TransientFailureKind::Deadlock];
        yield 'LockWaitTimeoutException' => [new LockWaitTimeoutException(self::driver(1205, 'HY000'), null), TransientFailureKind::LockWaitTimeout];
        yield 'raw driver 1205' => [self::driver(1205, 'HY000'), TransientFailureKind::LockWaitTimeout];
        yield 'raw driver 1213' => [self::driver(1213, '40001'), TransientFailureKind::Deadlock];
        yield 'lost savepoint 1305 alone (implicit commit)' => [self::lostSavepoint('SAVEPOINT DOCTRINE_2 does not exist'), null];
        yield 'lost savepoint 1305 over a unique violation' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', new DriverException(self::driver(1062, '23000'), null)), null), null];
        yield 'PostgreSQL lock_timeout 55P03' => [new DriverException(self::driver(7, '55P03'), null), TransientFailureKind::LockWaitTimeout];
        yield 'MariaDB snapshot conflict 1020' => [new DriverException(self::driver(1020, 'HY000'), null), TransientFailureKind::SerializationFailure];
        yield '1305 chained to its deadlock' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', new DeadlockException(self::driver(1213, '40001'), null)), null), TransientFailureKind::Deadlock];
        yield 'other RetryableException' => [new class('retry') extends Exception implements RetryableException {}, TransientFailureKind::Retryable];
        yield 'TransientStorageFailure without a storage error' => [new TransientStorageFailure(), TransientFailureKind::Retryable];
        yield 'TransientStorageFailure over a lock wait' => [TransientStorageFailure::fromPrevious(new LockWaitTimeoutException(self::driver(1205, 'HY000'), null)), TransientFailureKind::LockWaitTimeout];
        yield 'TransientStorageFailure over a non-storage error' => [TransientStorageFailure::fromPrevious(new RuntimeException('other')), TransientFailureKind::Retryable];
        yield 'TransientStorageFailure wrapped by the application' => [new LogicException('wrapper', 0, new TransientStorageFailure(5)), TransientFailureKind::Retryable];
        yield 'HandlerFailedException over a deadlock' => [self::handlerFailed(['h' => new DeadlockException(self::driver(1213, '40001'), null)]), TransientFailureKind::Deadlock];
        yield 'HandlerFailedException, deadlock in the second handler only' => [self::handlerFailed(['a' => new LogicException('bug'), 'b' => new DeadlockException(self::driver(1213, '40001'), null)]), TransientFailureKind::Deadlock];
        yield 'HandlerFailedException nested in a HandlerFailedException' => [self::handlerFailed(['a' => new LogicException('bug'), 'b' => self::handlerFailed(['c' => new RuntimeException('x'), 'd' => new LockWaitTimeoutException(self::driver(1205, 'HY000'), null)])]), TransientFailureKind::LockWaitTimeout];
        yield 'HandlerFailedException over a TransientStorageFailure' => [self::handlerFailed(['a' => new LogicException('bug'), 'b' => new TransientStorageFailure(4)]), TransientFailureKind::Retryable];
        yield 'HandlerFailedException over a TransientStorageFailure over a lock wait' => [self::handlerFailed(['a' => TransientStorageFailure::fromPrevious(new LockWaitTimeoutException(self::driver(1205, 'HY000'), null))]), TransientFailureKind::LockWaitTimeout];
        yield 'HandlerFailedException over application errors only' => [self::handlerFailed(['a' => new LogicException('bug'), 'b' => new RuntimeException('not a deadlock', 1213)]), null];
        yield 'application code 1213' => [new RuntimeException('not a deadlock', 1213), null];
        yield 'wrapped deadlock' => [new RuntimeException('wrapper', 0, new DeadlockException(self::driver(1213, '40001'), null)), TransientFailureKind::Deadlock];
        yield 'constraint violation' => [new DriverException(self::driver(1062, '23000'), null), null];
        yield 'PDO errorInfo 1213' => [self::pdo('HY000', [null, 1213]), TransientFailureKind::Deadlock];
        yield 'PDO errorInfo 1205' => [self::pdo('HY000', ['HY000', 1205]), TransientFailureKind::LockWaitTimeout];
        yield 'PDO SQLSTATE 40001 without errorInfo' => [self::pdo('40001', null), TransientFailureKind::SerializationFailure];
        yield 'PDO other' => [self::pdo('23000', ['23000', 1062]), null];
    }

    #[DataProvider('failures')]
    public function testClassify(Throwable $failure, ?TransientFailureKind $expected): void
    {
        $classifier = new TransientFailureClassifier();

        self::assertSame($expected, $classifier->classify($failure));
        self::assertSame($expected instanceof TransientFailureKind, $classifier->isTransient($failure));
    }

    public function testTransientCauseIsTheFirstTransientExceptionOfTheChain(): void
    {
        $deadlock   = new DeadlockException(self::driver(1213, '40001'), null);
        $lost       = new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', $deadlock), null);
        $classifier = new TransientFailureClassifier();

        self::assertSame($deadlock, $classifier->transientCause($lost));
        self::assertSame($deadlock, $classifier->transientCause($deadlock));
        self::assertNull($classifier->transientCause(new RuntimeException('bug')));
    }

    public function testTransientCauseAndStorageFailureInsideAHandlerFailedException(): void
    {
        $deadlock   = new DeadlockException(self::driver(1213, '40001'), null);
        $storage    = new TransientStorageFailure(6);
        $failed     = self::handlerFailed(['a' => new LogicException('bug'), 'b' => $storage, 'c' => $deadlock]);
        $classifier = new TransientFailureClassifier();

        self::assertSame($deadlock, $classifier->transientCause($failed), 'A classified storage error wins over a bare TransientStorageFailure.');
        self::assertSame($storage, $classifier->storageFailure($failed));
        self::assertSame($storage, $classifier->transientCause(self::handlerFailed(['a' => new LogicException('bug'), 'b' => $storage])));
        self::assertNull($classifier->storageFailure($deadlock));
    }

    public function testADeadlockBeyondTheDepthBoundIsNotFound(): void
    {
        $current = new DeadlockException(self::driver(1213, '40001'), null);

        for ($i = 0; $i < ThrowableChain::MAX_DEPTH; ++$i) {
            $current = new RuntimeException('level', 0, $current);
        }

        $classifier = new TransientFailureClassifier();
        self::assertTrue($classifier->isTransient($current), 'At the bound: still found.');
        self::assertFalse($classifier->isTransient(new RuntimeException('one more', 0, $current)), 'Beyond the bound: not walked.');
    }

    /**
     * @param array<string, Throwable> $exceptions
     */
    private static function handlerFailed(array $exceptions): HandlerFailedException
    {
        return new HandlerFailedException(new Envelope(new stdClass()), $exceptions);
    }

    private static function driver(int $code, ?string $sqlState, string $message = 'driver error', ?Throwable $previous = null): DriverExceptionInterface
    {
        return new class($message, $code, $previous, $sqlState) extends Exception implements DriverExceptionInterface {
            public function __construct(string $message, int $code, ?Throwable $previous, private readonly ?string $sqlState)
            {
                parent::__construct($message, $code, $previous);
            }

            public function getSQLState(): ?string
            {
                return $this->sqlState;
            }
        };
    }

    private static function lostSavepoint(string $message): DriverException
    {
        return new DriverException(self::driver(1305, '42000', 'SQLSTATE[42000]: Syntax error or access violation: 1305 ' . $message), null);
    }

    /**
     * @param array<int, int|string|null>|null $errorInfo
     */
    private static function pdo(string $sqlState, ?array $errorInfo): PDOException
    {
        $pdo = new PDOException('pdo');
        (new ReflectionProperty(PDOException::class, 'code'))->setValue($pdo, $sqlState);
        $pdo->errorInfo = $errorInfo;

        return $pdo;
    }
}
