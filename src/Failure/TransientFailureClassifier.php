<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Failure;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\RetryableException;
use Nowo\TransactionContract\TransientStorageFailure;
use PDOException;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

use function class_exists;
use function is_int;
use function is_string;

/**
 * Tells whether a failure is transient (replaying the whole transaction can succeed), walking the previous chain and
 * the exceptions a Messenger HandlerFailedException wraps (see ThrowableChain::resolve(): recursive, depth-bounded).
 *
 * A Nowo\TransactionContract\TransientStorageFailure without a previous error is Retryable. One that carries a
 * recognised storage error is classified by that error (so a lock wait timeout stays under retry_lock_wait_timeout);
 * one that carries anything else (a cache or HTTP error, whose work may already be committed) is not transient.
 *
 * A HandlerFailedException is transient only when EVERY exception it wraps is transient and its envelope carries no
 * HandledStamp: a handler that completed in the failed attempt, or a plain error in another handler, makes a replay
 * unsafe. Its kind is the strongest one among the wrapped failures (deadlock or serialization failure before
 * Retryable before lock wait timeout), whatever their order. A DelayedMessageHandlingException (handlers run after the
 * outer dispatch completed) is never transient.
 *
 * Only driver-level errors carry a vendor code: an application exception that happens to use code 1213 is not
 * transient. A 1305 "SAVEPOINT DOCTRINE_n does not exist" is never transient on its own: savepoints also vanish on an
 * implicit commit (DDL inside the transaction), after which replaying would repeat committed work. After a nested
 * deadlock the ORM's 1305 carries the deadlock in its previous chain (PHP chains the exception a finally block
 * replaces), and the deadlock is what makes it transient. A connection lost during a commit is not transient either:
 * its outcome is unknown.
 *
 * The classifier only reads error codes; it never returns or logs an exception message, which can contain SQL and
 * parameters. Stateless: safe as a shared service in a long-running worker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransientFailureClassifier
{
    /**
     * @return TransientFailureKind|null The kind of the first transient failure in the chain, or null when there is none
     */
    public function classify(Throwable $throwable): ?TransientFailureKind
    {
        $cause = $this->transientCause($throwable);

        if ($cause instanceof TransientStorageFailure) {
            return TransientFailureKind::Retryable;
        }

        return $cause instanceof Throwable ? $this->classifyOne($cause) : null;
    }

    public function isTransient(Throwable $throwable): bool
    {
        return $this->transientCause($throwable) instanceof Throwable;
    }

    /**
     * @return Throwable|null The transient failure that makes the chain transient (a storage error, or a
     *                        TransientStorageFailure without a previous error), or null when the chain is not transient
     */
    public function transientCause(Throwable $throwable): ?Throwable
    {
        return ThrowableChain::resolve(
            $throwable,
            function (Throwable $current): ?Throwable {
                if ($current instanceof TransientStorageFailure) {
                    // Without a previous error: plainly retryable. With one: classified by what it carries.
                    return $current->getPrevious() instanceof Throwable ? null : $current;
                }

                return $this->classifyOne($current) instanceof TransientFailureKind ? $current : null;
            },
            static fn (Throwable $wrapper): bool => self::replayableWrapper($wrapper),
            fn (array $causes): Throwable => $this->strongest($causes),
        );
    }

    /**
     * @return TransientStorageFailure|null The first TransientStorageFailure of the chain (the throwable itself included),
     *                                      for its Retry-After hint
     */
    public function storageFailure(Throwable $throwable): ?TransientStorageFailure
    {
        foreach (ThrowableChain::flatten($throwable) as $current) {
            if ($current instanceof TransientStorageFailure) {
                return $current;
            }
        }

        return null;
    }

    /**
     * A wrapper whose replay would run again work that already completed is never transient.
     */
    private static function replayableWrapper(Throwable $wrapper): bool
    {
        if (class_exists(DelayedMessageHandlingException::class) && $wrapper instanceof DelayedMessageHandlingException) {
            return false;
        }

        if (class_exists(HandlerFailedException::class) && $wrapper instanceof HandlerFailedException) {
            return $wrapper->getEnvelope()->all(HandledStamp::class) === [];
        }

        return true;
    }

    /**
     * @param non-empty-list<Throwable> $causes
     */
    private function strongest(array $causes): Throwable
    {
        $best     = $causes[0];
        $bestRank = $this->rank($best);

        foreach ($causes as $cause) {
            if ($this->rank($cause) > $bestRank) {
                $best     = $cause;
                $bestRank = $this->rank($cause);
            }
        }

        return $best;
    }

    private function rank(Throwable $cause): int
    {
        $kind = $cause instanceof TransientStorageFailure ? TransientFailureKind::Retryable : $this->classifyOne($cause);

        return match ($kind) {
            TransientFailureKind::Deadlock, TransientFailureKind::SerializationFailure => 3,
            TransientFailureKind::Retryable                                            => 2,
            TransientFailureKind::LockWaitTimeout                                      => 1,
            null                                                                       => 0,
        };
    }

    private function classifyOne(Throwable $throwable): ?TransientFailureKind
    {
        if ($throwable instanceof LockWaitTimeoutException) {
            return TransientFailureKind::LockWaitTimeout;
        }

        if ($throwable instanceof DriverExceptionInterface) {
            $kind = $this->fromCodes($throwable->getCode(), $throwable->getSQLState());
        } elseif ($throwable instanceof PDOException) {
            $vendorCode = $throwable->errorInfo[1] ?? null;
            $sqlState   = $throwable->errorInfo[0] ?? $throwable->getCode();
            $kind       = $this->fromCodes(is_int($vendorCode) ? $vendorCode : 0, is_string($sqlState) ? $sqlState : null);
        } else {
            $kind = null;
        }

        if ($kind instanceof TransientFailureKind) {
            return $kind;
        }

        if ($throwable instanceof DeadlockException) {
            return TransientFailureKind::Deadlock;
        }

        return $throwable instanceof RetryableException ? TransientFailureKind::Retryable : null;
    }

    private function fromCodes(int $vendorCode, ?string $sqlState): ?TransientFailureKind
    {
        return match (true) {
            $vendorCode === 1213, $sqlState === '40P01' => TransientFailureKind::Deadlock,
            $vendorCode === 1205, $sqlState === '55P03' => TransientFailureKind::LockWaitTimeout,
            $sqlState === '40001', $vendorCode === 1020 => TransientFailureKind::SerializationFailure,
            default                                     => null,
        };
    }
}
