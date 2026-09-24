<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Service;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\Exception\UnknownRetryProfileException;
use RuntimeException;
use Throwable;

/**
 * Executes Doctrine flush (and arbitrary callables) with retries on deadlock.
 *
 * When a failure leaves the entity manager closed (Doctrine ORM closes it on every failed flush), the manager is
 * reset through the ManagerRegistry so the next attempt, and the next request served by a long-running worker,
 * get an open manager.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DeadlockRetryService
{
    /**
     * @param array<string, RetryProfile> $profiles
     * @param ManagerRegistry|null $managerRegistry Used to reset a closed entity manager; without it a closed manager cannot be recovered
     * @param string|null $entityManagerName Entity manager name in the registry (null = default manager)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $profiles,
        private readonly string $defaultProfile,
        private readonly ?ManagerRegistry $managerRegistry = null,
        private readonly ?string $entityManagerName = null,
    ) {
    }

    /**
     * Flushes the entity manager, retrying on deadlock using the given or default profile.
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
     * Runs a callable with deadlock retries.
     *
     * The callable should obtain the entity manager on each attempt (e.g. via getEntityManager()) and redo its
     * whole unit of work, because a closed manager is reset between attempts.
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
        $attempt      = 0;
        $lastError    = null;

        while ($attempt < $retryProfile->maxAttempts()) {
            ++$attempt;

            try {
                return $operation();
            } catch (Throwable $throwable) {
                if (!$this->isDeadlock($throwable)) {
                    $this->recoverClosedManager();

                    throw $throwable;
                }

                $this->prepareForRetry($retryProfile);
                $managerWasClosed = $this->recoverClosedManager();

                if ($attempt >= $retryProfile->maxAttempts() || ($managerWasClosed && !$replayableAfterClose)) {
                    throw $throwable;
                }

                $lastError = $throwable;
                $this->sleep($retryProfile);
            }
        }

        throw $lastError ?? new RuntimeException('Deadlock retry loop ended without result.');
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

    private function prepareForRetry(RetryProfile $profile): void
    {
        if (!$profile->rollbackOnDeadlock) {
            return;
        }

        $manager    = $this->currentManager();
        $connection = $manager->getConnection();

        if ($connection->isTransactionActive()) {
            $manager->rollback();
        }
    }

    private function sleep(RetryProfile $profile): void
    {
        $microseconds = $profile->sleepMicroseconds();

        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }

    private function isDeadlock(Throwable $throwable): bool
    {
        $current = $throwable;

        while (true) {
            if ($current instanceof DeadlockException) {
                return true;
            }

            if ($current instanceof DriverException) {
                $sqlState = $current->getSQLState();

                if ($sqlState === '40001' || $current->getCode() === 1213) {
                    return true;
                }
            }

            $code = $current->getCode();

            if ($code === 1213 || $code === '1213') {
                return true;
            }

            $previous = $current->getPrevious();

            if (!$previous instanceof Throwable) {
                return false;
            }

            $current = $previous;
        }
    }
}
