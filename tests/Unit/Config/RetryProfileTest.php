<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Config;

use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function count;

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
