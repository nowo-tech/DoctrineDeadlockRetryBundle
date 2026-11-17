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

    public function testZeroAndNegativeDelaysReturnAtOnce(): void
    {
        $sleeper = new UsleepSleeper();
        $start   = hrtime(true);

        $sleeper->sleepMilliseconds(0);
        $sleeper->sleepMilliseconds(-50);

        self::assertLessThan(20.0, (hrtime(true) - $start) / 1e6);
    }
}
