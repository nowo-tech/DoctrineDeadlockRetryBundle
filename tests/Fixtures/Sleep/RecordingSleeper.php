<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\Sleep;

use Nowo\DoctrineDeadlockRetryBundle\Sleep\SleeperInterface;

/**
 * Test double: records each requested delay instead of sleeping.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RecordingSleeper implements SleeperInterface
{
    /** @var list<int> */
    public array $delays = [];

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
    }
}
