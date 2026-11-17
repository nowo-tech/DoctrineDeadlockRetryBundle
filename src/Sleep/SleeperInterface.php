<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Sleep;

/**
 * Waits between retry attempts. Port of DeadlockRetryService: the default UsleepSleeper blocks the process; a host
 * aliases this interface to its own implementation (a test double that records the delays, a clock-driven sleeper).
 *
 * Implementations must keep no per-request state: the service is shared by every request of a long-running worker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SleeperInterface
{
    /**
     * @param int $milliseconds Delay before the next attempt (never negative; 0 or less means no wait)
     */
    public function sleepMilliseconds(int $milliseconds): void;
}
