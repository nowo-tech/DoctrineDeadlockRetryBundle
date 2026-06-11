<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Config;

/**
 * Retry policy for a named profile (max retries, sleep, rollback behaviour).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RetryProfile
{
    /**
     * @param string $name Profile identifier
     * @param int $maxRetries Retries after the first failure
     * @param int $sleepMs Milliseconds between attempts
     * @param bool $rollbackOnDeadlock Whether to roll back before retrying
     */
    public function __construct(
        public readonly string $name,
        public readonly int $maxRetries,
        public readonly int $sleepMs,
        public readonly bool $rollbackOnDeadlock = true,
    ) {
    }

    /**
     * @return int Sleep duration in microseconds (sleep_ms × 1000)
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
}
