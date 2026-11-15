<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Failure;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\RetryableException;
use Nowo\TransactionContract\TransientStorageFailure;
use PDOException;
use Throwable;

use function is_int;
use function is_string;

/**
 * Tells whether a failure is transient (replaying the whole transaction can succeed), walking the previous chain.
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

        return $cause instanceof Throwable ? $this->classifyOne($cause) : null;
    }

    public function isTransient(Throwable $throwable): bool
    {
        return $this->transientCause($throwable) instanceof Throwable;
    }

    /**
     * @return Throwable|null The first exception of the chain (the throwable itself included) that is a transient failure
     */
    public function transientCause(Throwable $throwable): ?Throwable
    {
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($this->classifyOne($current) instanceof TransientFailureKind) {
                return $current;
            }
        }

        return null;
    }

    private function classifyOne(Throwable $throwable): ?TransientFailureKind
    {
        // A TransientStorageFailure from a nested runner is classified by the storage error it carries (so a lock
        // wait timeout stays under retry_lock_wait_timeout); one without a previous error is plainly retryable.
        if ($throwable instanceof TransientStorageFailure) {
            return $throwable->getPrevious() instanceof Throwable ? null : TransientFailureKind::Retryable;
        }

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
