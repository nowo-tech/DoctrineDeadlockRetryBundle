<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Service;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\Exception\UnknownRetryProfileException;
use RuntimeException;
use Throwable;

/**
 * Executes Doctrine flush (and arbitrary callables) with retries on deadlock.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DeadlockRetryService
{
    /**
     * @param array<string, RetryProfile> $profiles
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $profiles,
        private readonly string $defaultProfile,
    ) {
    }

    /**
     * Flushes the entity manager, retrying on deadlock using the given or default profile.
     *
     * @param string|null $profile Optional profile name; uses the configured default when null
     */
    public function flush(?string $profile = null): void
    {
        $this->retry(function (): void {
            $this->entityManager->flush();
        }, $profile);
    }

    /**
     * Runs a callable with deadlock retries.
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
        $retryProfile = $this->resolveProfile($profile);
        $attempt      = 0;
        $lastError    = null;

        while ($attempt < $retryProfile->maxAttempts()) {
            ++$attempt;

            try {
                return $operation();
            } catch (Throwable $throwable) {
                if (!$this->isDeadlock($throwable) || $attempt >= $retryProfile->maxAttempts()) {
                    throw $throwable;
                }

                $lastError = $throwable;
                $this->prepareForRetry($retryProfile);
                $this->sleep($retryProfile);
            }
        }

        throw $lastError ?? new RuntimeException('Deadlock retry loop ended without result.');
    }

    /**
     * @return list<string>
     */
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

    private function resolveProfile(?string $profile): RetryProfile
    {
        $name = $profile ?? $this->defaultProfile;

        if (!isset($this->profiles[$name])) {
            throw UnknownRetryProfileException::forName($name, $this->getProfileNames());
        }

        return $this->profiles[$name];
    }

    private function prepareForRetry(RetryProfile $profile): void
    {
        if (!$profile->rollbackOnDeadlock) {
            return;
        }

        $connection = $this->entityManager->getConnection();

        if ($connection->isTransactionActive()) {
            $this->entityManager->rollback();
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
