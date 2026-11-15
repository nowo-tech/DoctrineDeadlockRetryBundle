<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps every log record, for assertions.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
