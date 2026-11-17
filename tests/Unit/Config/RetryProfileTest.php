<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Config;

use InvalidArgumentException;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function count;
use function max;
use function min;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RetryProfileTest extends TestCase
{
    public function testExponentialBoundWithoutJitterIsCapped(): void
    {
        $profile    = new RetryProfile('p', 6, 100, backoffMaxMs: 500, fullJitter: false);
        $randomizer = new Randomizer(new Mt19937(1));

        self::assertSame(100, $profile->delayMilliseconds(1, $randomizer));
        self::assertSame(200, $profile->delayMilliseconds(2, $randomizer));
        self::assertSame(400, $profile->delayMilliseconds(3, $randomizer));
        self::assertSame(500, $profile->delayMilliseconds(4, $randomizer));
        self::assertSame(500, $profile->delayMilliseconds(30, $randomizer));
    }

    public function testFullJitterStaysWithinTheBound(): void
    {
        $profile    = new RetryProfile('p', 3, 100, backoffMaxMs: 300);
        $randomizer = new Randomizer(new Mt19937(42));
        $seen       = [];

        for ($i = 0; $i < 200; ++$i) {
            $delay = $profile->delayMilliseconds(3, $randomizer);
            self::assertGreaterThanOrEqual(0, $delay);
            self::assertLessThanOrEqual(300, $delay);
            $seen[$delay] = true;
        }

        self::assertGreaterThan(50, count($seen), 'Delays are spread, not lockstep.');
    }

    public function testFullJitterWithAFloorStaysBetweenTheFloorAndTheBound(): void
    {
        $profile    = new RetryProfile('p', 3, 50, backoffMaxMs: 200, backoffMinMs: 20);
        $randomizer = new Randomizer(new Mt19937(7));

        foreach ([1 => 50, 2 => 100, 3 => 200, 4 => 200] as $retry => $bound) {
            $seen = [];

            for ($i = 0; $i < 300; ++$i) {
                $delay = $profile->delayMilliseconds($retry, $randomizer);
                self::assertGreaterThanOrEqual(20, $delay);
                self::assertLessThanOrEqual($bound, $delay);
                $seen[$delay] = true;
            }

            self::assertGreaterThan(20, count($seen), 'Delays are spread over [floor, bound].');
        }
    }

    public function testBeaconWriteUnitProfileJittersBetween20And200(): void
    {
        // max_retries 3, 1213 always retried, 1205 through retry_lock_wait_timeout, every delay in [20, 200] ms.
        $profile    = new RetryProfile('write_unit', 3, 200, backoffMaxMs: 200, retryLockWaitTimeout: true, backoffMinMs: 20);
        $randomizer = new Randomizer(new Mt19937(3));
        $min        = 200;
        $max        = 20;

        for ($i = 0; $i < 500; ++$i) {
            foreach ([1, 2, 3] as $retry) {
                $delay = $profile->delayMilliseconds($retry, $randomizer);
                $min   = min($min, $delay);
                $max   = max($max, $delay);
            }
        }

        self::assertSame(4, $profile->maxAttempts());
        self::assertTrue($profile->retryLockWaitTimeout);
        self::assertGreaterThanOrEqual(20, $min);
        self::assertLessThan(30, $min);
        self::assertLessThanOrEqual(200, $max);
        self::assertGreaterThan(190, $max);
    }

    public function testFloorRaisesABoundBelowItAndAppliesWithoutJitter(): void
    {
        $profile = new RetryProfile('p', 3, 10, backoffMaxMs: 100, fullJitter: false, backoffMinMs: 30);

        self::assertSame(30, $profile->delayMilliseconds(1, new Randomizer()));
        self::assertSame(30, $profile->delayMilliseconds(2, new Randomizer()));
        self::assertSame(40, $profile->delayMilliseconds(3, new Randomizer()));
        self::assertSame(30, (new RetryProfile('p', 3, 10, backoffMaxMs: 100, backoffMinMs: 30))->delayMilliseconds(1, new Randomizer()));
    }

    public function testFloorWithAZeroBaseWaitsTheFloor(): void
    {
        self::assertSame(25, (new RetryProfile('p', 3, 0, backoffMaxMs: 25, backoffMinMs: 25))->delayMilliseconds(2, new Randomizer()));
    }

    public function testFloorAboveTheCapIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('backoff_min_ms (201) must be between 0 and backoff_max_ms (200)');

        new RetryProfile('p', 3, 50, backoffMaxMs: 200, backoffMinMs: 201);
    }

    public function testNegativeFloorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RetryProfile('p', 3, 50, backoffMinMs: -1);
    }

    public function testZeroBaseNeverWaits(): void
    {
        self::assertSame(0, (new RetryProfile('p', 3, 0))->delayMilliseconds(3, new Randomizer()));
    }

    public function testCapIsNeverBelowTheBase(): void
    {
        $profile = new RetryProfile('p', 3, 5000, backoffMaxMs: 100, fullJitter: false);

        self::assertSame(5000, $profile->backoffMaxMs);
        self::assertSame(5000, $profile->delayMilliseconds(2, new Randomizer()));
        self::assertSame(5_000_000, $profile->sleepMicroseconds());
        self::assertSame(4, $profile->maxAttempts());
    }

    public function testDefaults(): void
    {
        $profile = new RetryProfile('p', 3, 100);

        self::assertTrue($profile->rollbackOnDeadlock);
        self::assertTrue($profile->fullJitter);
        self::assertFalse($profile->retryLockWaitTimeout);
        self::assertNull($profile->maxTotalMs);
        self::assertSame(0, $profile->backoffMinMs);
        self::assertSame(RetryProfile::DEFAULT_BACKOFF_MAX_MS, $profile->backoffMaxMs);
        self::assertTrue($profile->withinBudget(1e9, 1000));
    }

    public function testTimeBudget(): void
    {
        $profile = new RetryProfile('p', 3, 100, maxTotalMs: 500);

        self::assertTrue($profile->withinBudget(300.0, 200));
        self::assertFalse($profile->withinBudget(300.5, 200));
    }
}
