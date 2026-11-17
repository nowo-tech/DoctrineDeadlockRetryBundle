<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Sleep;

use Nowo\DoctrineDeadlockRetryBundle\Sleep\UsleepSleeper;
use PHPUnit\Framework\TestCase;

use function hrtime;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class UsleepSleeperTest extends TestCase
{
    public function testSleepsAtLeastTheRequestedMilliseconds(): void
    {
        $sleeper = new UsleepSleeper();

        $start = hrtime(true);
        $sleeper->sleepMilliseconds(5);

        self::assertGreaterThanOrEqual(5.0, (hrtime(true) - $start) / 1e6);
    }

    public function testZeroAndNegativeDelaysNeverReachUsleep(): void
    {
        // usleep(-50000) throws a ValueError: returning normally proves the guard skipped it.
        $this->expectNotToPerformAssertions();

        $sleeper = new UsleepSleeper();
        $sleeper->sleepMilliseconds(0);
        $sleeper->sleepMilliseconds(-50);
    }
}
