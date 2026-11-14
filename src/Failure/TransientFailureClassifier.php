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
use function preg_match;

/**
 * Tells whether a failure is transient (replaying the whole transaction can succeed), walking the previous chain.
 *
 * Only driver-level errors carry a vendor code: an application exception that happens to use code 1213 is not
 * transient. The classifier only matches error codes and Doctrine's own savepoint names; it never returns or logs
 * an exception message, which can contain SQL and parameters.
 *
 * Stateless: safe as a shared service in a long-running worker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransientFailureClassifier
{
    /** "SAVEPOINT DOCTRINE_2 does not exist" (DBAL 4) or "SAVEPOINT DOCTRINE2_SAVEPOINT_2 does not exist" (DBAL 3). */
    private const LOST_DOCTRINE_SAVEPOINT = '/SAVEPOINT\s+`?DOCTRINE(?:2_SAVEPOINT)?_\d+`?\s+does not exist/i';

    /**
     * @return TransientFailureKind|null The most precise kind found in the chain, or null when the failure is not transient
     */
    public function classify(Throwable $throwable): ?TransientFailureKind
    {
        $lostSavepoint = false;

        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            $kind = $this->classifyOne($current);

            if ($kind === TransientFailureKind::LostSavepoint) {
                // Keep looking: the deadlock itself may still be further down the chain.
                $lostSavepoint = true;

                continue;
            }

            if ($kind instanceof TransientFailureKind) {
                return $kind;
            }
        }

        return $lostSavepoint ? TransientFailureKind::LostSavepoint : null;
    }

    public function isTransient(Throwable $throwable): bool
    {
        return $this->classify($throwable) instanceof TransientFailureKind;
    }

    private function classifyOne(Throwable $throwable): ?TransientFailureKind
    {
        if ($throwable instanceof TransientStorageFailure) {
            return TransientFailureKind::Retryable;
        }

        if ($throwable instanceof LockWaitTimeoutException) {
            return TransientFailureKind::LockWaitTimeout;
        }

        if ($throwable instanceof DriverExceptionInterface) {
            $kind = $this->fromCodes($throwable->getCode(), $throwable->getSQLState(), $throwable);
        } elseif ($throwable instanceof PDOException) {
            $vendorCode = $throwable->errorInfo[1] ?? null;
            $sqlState   = $throwable->errorInfo[0] ?? $throwable->getCode();
            $kind       = $this->fromCodes(is_int($vendorCode) ? $vendorCode : 0, is_string($sqlState) ? $sqlState : null, $throwable);
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

    private function fromCodes(int $vendorCode, ?string $sqlState, Throwable $throwable): ?TransientFailureKind
    {
        return match (true) {
            $vendorCode === 1213, $sqlState === '40P01'                                                       => TransientFailureKind::Deadlock,
            $vendorCode === 1205                                                                              => TransientFailureKind::LockWaitTimeout,
            $sqlState === '40001'                                                                             => TransientFailureKind::SerializationFailure,
            $vendorCode === 1305 && preg_match(self::LOST_DOCTRINE_SAVEPOINT, $throwable->getMessage()) === 1 => TransientFailureKind::LostSavepoint,
            default                                                                                           => null,
        };
    }
}
