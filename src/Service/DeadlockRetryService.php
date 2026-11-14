<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\Connection\ConnectionRepairer;
use Nowo\DoctrineDeadlockRetryBundle\Contract\DeadlockRetryInterface;
use Nowo\DoctrineDeadlockRetryBundle\Exception\UnknownRetryProfileException;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureClassifier;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureKind;
use Psr\Log\LoggerInterface;
use Random\Randomizer;
use Throwable;

use function usleep;

/**
 * Executes Doctrine flush (and arbitrary callables) with retries on transient failures: deadlocks (1213, 40P01),
 * serialization failures (40001), lock wait timeouts (1205, per profile) and the 1305 "SAVEPOINT DOCTRINE_n does not
 * exist" that replaces a deadlock in a nested transaction on MySQL / MariaDB.
 *
 * After any failure the connection is repaired before anything else runs on it: at entry level 0 a transaction left
 * active is closed (Connection::close() resets the nesting level; a rollback is what fails after a deadlock), and
 * inside a caller's transaction the leftover levels are rolled back, or the connection closed when that fails.
 * A closed entity manager is reset through the ManagerRegistry so the next attempt, and the next request served by
 * a long-running worker, get an open manager.
 *
 * Inside a caller's transaction (nesting level > 0 when the call starts) nothing is retried: the database rolled
 * back, or aborted, the whole transaction, so only its owner can replay it. The failure is rethrown.
 *
 * Retries wait with exponential backoff and full jitter (see RetryProfile). The service holds no per-request state.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DeadlockRetryService implements DeadlockRetryInterface
{
    private readonly TransientFailureClassifier $classifier;

    private readonly ConnectionRepairer $repairer;

    private readonly Randomizer $randomizer;

    /**
     * @param array<string, RetryProfile> $profiles
     * @param ManagerRegistry|null $managerRegistry Used to reset a closed entity manager; without it a closed manager cannot be recovered
     * @param string|null $entityManagerName Entity manager name in the registry (null = default manager)
     * @param LoggerInterface|null $logger Receives retries and connection repairs (kind, codes and levels only, never SQL)
     * @param Randomizer|null $randomizer Source of the backoff jitter (a secure engine by default)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $profiles,
        private readonly string $defaultProfile,
        private readonly ?ManagerRegistry $managerRegistry = null,
        private readonly ?string $entityManagerName = null,
        private readonly ?LoggerInterface $logger = null,
        ?TransientFailureClassifier $classifier = null,
        ?ConnectionRepairer $repairer = null,
        ?Randomizer $randomizer = null,
    ) {
        $this->classifier = $classifier ?? new TransientFailureClassifier();
        $this->repairer   = $repairer ?? new ConnectionRepairer();
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * Flushes the entity manager, retrying on transient failures using the given or default profile.
     *
     * The flush is only retried while the entity manager stays open. If the deadlock closed it (the ORM default),
     * the pending changes are lost with the unit of work: the manager is reset and the deadlock is rethrown.
     * Use retry() with a callable that rebuilds the whole unit of work to retry ORM writes.
     *
     * @param string|null $profile Optional profile name; uses the configured default when null
     */
    public function flush(?string $profile = null): void
    {
        $this->execute(function (): void {
            $this->getEntityManager()->flush();
        }, $profile, false);
    }

    /**
     * Runs a callable with retries on transient failures.
     *
     * The callable should obtain the entity manager on each attempt (e.g. via getEntityManager()) and redo its
     * whole unit of work, because a closed manager is reset between attempts. Without a ManagerRegistry a closed
     * manager cannot be reset, so the original deadlock is rethrown instead of retrying.
     *
     * @template T
     *
     * @param callable(): T $operation Callable that performs the work (e.g. flush)
     * @param string|null $profile Optional profile name; uses the default when null
     *
     * @return T
     */
    public function retry(callable $operation, ?string $profile = null): mixed
    {
        return $this->execute($operation, $profile, true);
    }

    /**
     * Returns the entity manager to use now, resetting it through the registry if it is closed.
     */
    public function getEntityManager(): EntityManagerInterface
    {
        $this->recoverClosedManager();

        return $this->currentManager();
    }

    /**
     * @return list<string> Configured profile names
     */
    public function getProfileNames(): array
    {
        return array_keys($this->profiles);
    }

    /**
     * @return string Name of the profile used when none is passed to flush() or retry()
     */
    public function getDefaultProfileName(): string
    {
        return $this->defaultProfile;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function execute(callable $operation, ?string $profile, bool $replayableAfterClose): mixed
    {
        $retryProfile = $this->resolveProfile($profile);
        $connection   = $this->currentManager()->getConnection();
        $entryLevel   = $connection->getTransactionNestingLevel();
        $attempt      = 0;

        while (true) {
            ++$attempt;

            try {
                return $operation();
            } catch (Throwable $throwable) {
                $kind = $this->classifier->classify($throwable);

                if ($retryProfile->rollbackOnDeadlock) {
                    $this->repairConnection($connection, $entryLevel);
                }

                $managerWasClosed = $this->recoverClosedManager();

                if (!$kind instanceof TransientFailureKind || !$this->mayRetry($retryProfile, $kind, $entryLevel, $attempt)) {
                    throw $throwable;
                }

                // A closed manager can only be replaced through the registry: without one, every further attempt
                // would fail with "EntityManager is closed" and hide the original failure.
                if ($managerWasClosed && (!$replayableAfterClose || !$this->managerRegistry instanceof ManagerRegistry)) {
                    throw $throwable;
                }

                $delay = $retryProfile->delayMilliseconds($attempt, $this->randomizer);
                $this->logger?->info('Transient database failure, retrying the operation.', [
                    'kind'     => $kind->value,
                    'profile'  => $retryProfile->name,
                    'attempt'  => $attempt,
                    'delay_ms' => $delay,
                ]);

                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        }
    }

    private function mayRetry(RetryProfile $profile, TransientFailureKind $kind, int $entryLevel, int $attempt): bool
    {
        if ($attempt >= $profile->maxAttempts() || $entryLevel > 0) {
            return false;
        }

        return $kind !== TransientFailureKind::LockWaitTimeout || $profile->retryLockWaitTimeout;
    }

    private function repairConnection(Connection $connection, int $entryLevel): void
    {
        $level  = $connection->getTransactionNestingLevel();
        $action = $this->repairer->afterFailure($connection, $entryLevel);

        if ($action !== ConnectionRepairer::ACTION_NONE) {
            $this->logger?->notice('Repaired the Doctrine connection left with an open transaction after a failure.', [
                'nesting_level' => $level,
                'entry_level'   => $entryLevel,
                'action'        => $action,
            ]);
        }
    }

    private function resolveProfile(?string $profile): RetryProfile
    {
        $name = $profile ?? $this->defaultProfile;

        if (!isset($this->profiles[$name])) {
            throw UnknownRetryProfileException::forName($name, $this->getProfileNames());
        }

        return $this->profiles[$name];
    }

    private function currentManager(): EntityManagerInterface
    {
        if (!$this->managerRegistry instanceof ManagerRegistry) {
            return $this->entityManager;
        }

        $manager = $this->managerRegistry->getManager($this->entityManagerName);

        return $manager instanceof EntityManagerInterface ? $manager : $this->entityManager;
    }

    /**
     * @return bool Whether the manager was closed (it has been reset when a registry is available)
     */
    private function recoverClosedManager(): bool
    {
        if ($this->currentManager()->isOpen()) {
            return false;
        }

        $this->managerRegistry?->resetManager($this->entityManagerName);

        return true;
    }
}
