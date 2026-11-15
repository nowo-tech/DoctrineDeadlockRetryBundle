<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Transaction;

use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureClassifier;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Throwable;

/**
 * Doctrine adapter of nowo-tech/transaction-contract's port: runs the operation in EntityManager::wrapInTransaction()
 * (flush + commit) through DeadlockRetryService, so transient failures are retried with jittered backoff and the
 * connection and entity manager are repaired between attempts.
 *
 * When the retries are exhausted, or the call runs inside a caller's transaction (nothing is retried there), a
 * transient failure is rethrown as TransientStorageFailure with the configured Retry-After hint and the storage
 * error as previous. Other failures are rethrown unchanged.
 *
 * Stateless: safe as a shared service in a long-running worker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DeadlockRetryTransactionRunner implements TransactionRunnerInterface
{
    /**
     * @param int $retryAfterSeconds Hint carried by the TransientStorageFailure
     * @param string|null $profile Retry profile; the default profile when null
     */
    public function __construct(
        private readonly DeadlockRetryService $retry,
        private readonly TransientFailureClassifier $classifier,
        private readonly int $retryAfterSeconds = 1,
        private readonly ?string $profile = null,
    ) {
    }

    public function run(callable $operation): mixed
    {
        try {
            return $this->retry->retry(
                fn (): mixed => $this->retry->getEntityManager()->wrapInTransaction(static fn (): mixed => $operation()),
                $this->profile,
            );
        } catch (TransientStorageFailure $failure) {
            throw $failure;
        } catch (Throwable $throwable) {
            if ($this->classifier->isTransient($throwable)) {
                throw TransientStorageFailure::fromPrevious($throwable, $this->retryAfterSeconds);
            }

            throw $throwable;
        }
    }
}
