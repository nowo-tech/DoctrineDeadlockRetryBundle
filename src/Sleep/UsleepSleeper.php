<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Sleep;

use function usleep;

/**
 * Default SleeperInterface: blocks the current process with usleep(). Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class UsleepSleeper implements SleeperInterface
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
