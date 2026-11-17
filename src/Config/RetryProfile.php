<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Config;

use InvalidArgumentException;
use Random\Randomizer;

use function max;
use function min;
use function sprintf;

/**
 * Retry policy for a named profile: attempts, backoff, cleanup and which transient failures are retried.
 *
 * The delay before retry n (1-based) is bounded by min(backoffMaxMs, sleepMs × 2^(n-1)). With full jitter (the default)
 * the actual delay is a uniform random value between backoffMinMs (0 by default) and that bound, so concurrent workers
 * do not retry in lockstep. A bound below backoffMinMs is raised to it; without jitter the delay is the bound itself
 * (never below backoffMinMs).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class RetryProfile
{
    /** Upper bound of a single delay when none is configured (milliseconds). */
    public const DEFAULT_BACKOFF_MAX_MS = 2000;

    /** @var int Upper bound of a single delay in milliseconds (never below sleepMs) */
    public int $backoffMaxMs;

    /**
     * @param string $name Profile identifier
     * @param int $maxRetries Retries after the first failure
     * @param int $sleepMs Backoff base in milliseconds (configuration key backoff_base_ms, alias sleep_ms)
     * @param bool $rollbackOnDeadlock Whether to repair the connection (close it, or roll back inside a caller's transaction) before retrying
     * @param int $backoffMaxMs Upper bound of a single delay in milliseconds
     * @param bool $fullJitter Whether each delay is a random value between 0 and the exponential bound
     * @param bool $retryLockWaitTimeout Whether lock wait timeouts (1205, 55P03) are retried, not only deadlocks and serialization failures
     * @param int|null $maxTotalMs Time budget of the whole call in milliseconds: no retry starts once the elapsed time plus the next delay would exceed it (null = no budget)
     * @param int $backoffMinMs Lower bound of a single delay in milliseconds (0 = none); at most backoffMaxMs
     *
     * @throws InvalidArgumentException When backoffMinMs is negative or above the effective backoffMaxMs
     */
    public function __construct(
        public string $name,
        public int $maxRetries,
        public int $sleepMs,
        public bool $rollbackOnDeadlock = true,
        int $backoffMaxMs = self::DEFAULT_BACKOFF_MAX_MS,
        public bool $fullJitter = true,
        public bool $retryLockWaitTimeout = false,
        public ?int $maxTotalMs = null,
        public int $backoffMinMs = 0,
    ) {
        $this->backoffMaxMs = max($backoffMaxMs, $sleepMs);

        if ($backoffMinMs < 0 || $backoffMinMs > $this->backoffMaxMs) {
            throw new InvalidArgumentException(sprintf('Retry profile "%s": backoff_min_ms (%d) must be between 0 and backoff_max_ms (%d).', $name, $backoffMinMs, $this->backoffMaxMs));
        }
    }

    /**
     * @return int Sleep duration in microseconds of the backoff base (sleep_ms × 1000)
     */
    public function sleepMicroseconds(): int
    {
        return $this->sleepMs * 1000;
    }

    /**
     * @return int Total attempts including the first try (max_retries + 1)
     */
    public function maxAttempts(): int
    {
        return $this->maxRetries + 1;
    }

    /**
     * @param float $elapsedMs Time spent in the call so far
     * @param int $nextDelayMs Delay before the next attempt
     *
     * @return bool Whether the time budget allows another attempt
     */
    public function withinBudget(float $elapsedMs, int $nextDelayMs): bool
    {
        return $this->maxTotalMs === null || $elapsedMs + $nextDelayMs <= $this->maxTotalMs;
    }

    /**
     * @param int $retry 1 for the delay before the first retry, 2 before the second, …
     *
     * @return int Delay in milliseconds before that retry
     */
    public function delayMilliseconds(int $retry, Randomizer $randomizer): int
    {
        if ($this->sleepMs === 0 && $this->backoffMinMs === 0) {
            return 0;
        }

        $bound = $this->sleepMs;

        for ($step = 1; $step < $retry && $bound < $this->backoffMaxMs; ++$step) {
            $bound *= 2;
        }

        $bound = max(min($bound, $this->backoffMaxMs), $this->backoffMinMs);

        return $this->fullJitter ? $randomizer->getInt($this->backoffMinMs, $bound) : $bound;
    }
}
