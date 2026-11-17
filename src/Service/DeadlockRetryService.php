<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\Connection\ConnectionRepairer;
use Nowo\DoctrineDeadlockRetryBundle\Contract\DeadlockRetryInterface;
use Nowo\DoctrineDeadlockRetryBundle\Exception\UnknownRetryProfileException;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureClassifier;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureKind;
use Nowo\DoctrineDeadlockRetryBundle\Sleep\SleeperInterface;
use Nowo\DoctrineDeadlockRetryBundle\Sleep\UsleepSleeper;
use PDOException;
use Psr\Log\LoggerInterface;
use Random\Randomizer;
use Throwable;

use function hrtime;
use function max;
use function str_starts_with;

/**
 * Executes Doctrine flush (and arbitrary callables) with retries on transient failures: deadlocks (1213, 40P01),
 * serialization failures (40001, MariaDB 1020) and, per profile, lock wait timeouts (1205, 55P03). The ORM's 1305
 * "SAVEPOINT DOCTRINE_n does not exist" after a nested deadlock is transient through the deadlock it carries; when
 * the service gives up, that deadlock is what it rethrows.
 *
 * After any failure the connection is repaired before anything else runs on it: the levels the operation left open
 * are rolled back, and the connection is closed only when that rollback fails (after a nested deadlock the
 * savepoints are gone; Connection::close() resets the nesting level). Before a replay an open entity manager is
 * cleared, so the failed attempt's changes are not flushed again.
 * A closed entity manager is reset through the ManagerRegistry so the next attempt, and the next request served by
 * a long-running worker, get an open manager.
 *
 * Inside a caller's transaction (nesting level > 0 when the call starts, > 1 with auto-commit disabled) nothing is
 * retried: the database rolled back, or aborted, the whole transaction, so only its owner can replay it. With
 * rollback_on_deadlock disabled nothing is retried on a connection the failure left in a transaction. Retries stop
 * at max_retries or when the profile's max_total_ms budget would be exceeded.
 *
 * Retries wait with exponential backoff and full jitter (see RetryProfile) through a SleeperInterface (usleep() by
 * default). A failure counts as transient wherever it sits in the previous chain or inside a Messenger
 * HandlerFailedException (see TransientFailureClassifier). The service holds no per-request state.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DeadlockRetryService implements DeadlockRetryInterface
{
    private readonly TransientFailureClassifier $classifier;

    private readonly ConnectionRepairer $repairer;

    private readonly Randomizer $randomizer;

    private readonly SleeperInterface $sleeper;

    /**
     * @param array<string, RetryProfile> $profiles
     * @param ManagerRegistry|null $managerRegistry Used to reset a closed entity manager; without it a closed manager cannot be recovered
     * @param string|null $entityManagerName Entity manager name in the registry (null = default manager)
     * @param LoggerInterface|null $logger Receives retries and connection repairs (kind, codes and levels only, never SQL)
     * @param Randomizer|null $randomizer Source of the backoff jitter (a secure engine by default)
     * @param SleeperInterface|null $sleeper Waits between attempts (UsleepSleeper by default)
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
        ?SleeperInterface $sleeper = null,
    ) {
        $this->classifier = $classifier ?? new TransientFailureClassifier();
        $this->repairer   = $repairer ?? new ConnectionRepairer();
        $this->randomizer = $randomizer ?? new Randomizer();
        $this->sleeper    = $sleeper ?? new UsleepSleeper();
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
        // With auto-commit disabled DBAL keeps an implicit transaction open at level 1: that is the outermost level.
        $floor     = max($entryLevel, $connection->isAutoCommit() ? 0 : 1);
        $outermost = $floor === ($connection->isAutoCommit() ? 0 : 1);
        $startedAt = hrtime(true);
        $attempt   = 0;

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

                if (!$kind instanceof TransientFailureKind) {
                    throw $throwable;
                }

                $delay = $retryProfile->delayMilliseconds($attempt, $this->randomizer);

                if (!$outermost
                    || !$this->mayRetry($retryProfile, $kind, $attempt)
                    // Never replay on a connection the failure left in a transaction (rollback_on_deadlock: false):
                    // the replay would run inside ghost savepoints and auto-commit.
                    || $connection->getTransactionNestingLevel() > $floor
                    // A closed manager can only be replaced through the registry: without one, every further attempt
                    // would fail with "EntityManager is closed" and hide the original failure.
                    || ($managerWasClosed && (!$replayableAfterClose || !$this->managerRegistry instanceof ManagerRegistry))
                    || !$retryProfile->withinBudget((hrtime(true) - $startedAt) / 1e6, $delay)
                ) {
                    throw $this->escapingFailure($throwable);
                }

                if ($replayableAfterClose) {
                    $this->clearOpenManager();
                }

                if (!$connection->isAutoCommit()) {
                    $this->restartImplicitTransaction($connection);
                }

                $this->logger?->info('Transient database failure, retrying the operation.', [
                    'kind'     => $kind->value,
                    'profile'  => $retryProfile->name,
                    'attempt'  => $attempt,
                    'delay_ms' => $delay,
                ]);

                if ($delay > 0) {
                    $this->sleeper->sleepMilliseconds($delay);
                }
            }
        }
    }

    private function mayRetry(RetryProfile $profile, TransientFailureKind $kind, int $attempt): bool
    {
        if ($attempt >= $profile->maxAttempts()) {
            return false;
        }

        return $kind !== TransientFailureKind::LockWaitTimeout || $profile->retryLockWaitTimeout;
    }

    /**
     * The transient failure itself when only Doctrine wrapped it (the ORM's 1305 after a nested deadlock, "Commit
     * failed"), so callers catching DeadlockException / RetryableException still see it; the throwable as is when the
     * application wrapped it in its own exception.
     */
    private function escapingFailure(Throwable $throwable): Throwable
    {
        $cause = $this->classifier->transientCause($throwable) ?? $throwable;

        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current === $cause) {
                return $cause;
            }

            if (!$current instanceof DriverExceptionInterface && !$current instanceof PDOException && !str_starts_with($current::class, 'Doctrine\\')) {
                return $throwable;
            }
        }

        // Only reachable through a wrapper's wrapped exceptions (HandlerFailedException): keep the wrapper.
        return $throwable;
    }

    /**
     * The failed attempt's entities stay scheduled in an open manager (a raw DBAL failure does not close it): clear
     * it, so the replay does not insert them a second time.
     */
    private function clearOpenManager(): void
    {
        $manager = $this->currentManager();

        if ($manager->isOpen()) {
            // @igor-ignore - clearing removes the failed attempt's state; it is the fix for a leak, not a leak
            $manager->clear();
        }
    }

    /**
     * With auto-commit disabled the failed attempt ran in the implicit transaction: roll it back (DBAL opens a new
     * one) so the replay starts clean; close when that rollback fails.
     */
    private function restartImplicitTransaction(Connection $connection): void
    {
        if (!$connection->isTransactionActive()) {
            return;
        }

        try {
            $connection->rollBack();
        } catch (Throwable) {
            $connection->close();
        }
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
