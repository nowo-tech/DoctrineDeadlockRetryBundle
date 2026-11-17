<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Failure;

use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Throwable;

use function array_pop;
use function array_reverse;
use function count;
use function interface_exists;
use function spl_object_id;

/**
 * Walks a failure and everything it carries: getPrevious() and, when symfony/messenger is installed, the exceptions
 * a WrappedExceptionsInterface holds (HandlerFailedException, DelayedMessageHandlingException), recursively.
 *
 * Order is depth-first, pre-order, previous before wrapped: for a plain chain it is exactly the getPrevious() order.
 * Each throwable is listed once (HandlerFailedException's previous is also its first wrapped exception, and a
 * custom wrapper may point back to itself); one reached again by a shorter path is expanded again from that depth,
 * so what it carries is not lost to the depth bound. The walk stops at MAX_DEPTH levels below the throwable and after
 * MAX_NODES throwables (and MAX_STEPS stack entries), so a hostile or accidental cycle or fan-out cannot hang a worker.
 *
 * Stateless: safe in a long-running worker.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ThrowableChain
{
    /** Deepest level walked below the throwable itself (its previous is level 1). */
    public const MAX_DEPTH = 64;

    /** Most throwables visited in one walk. */
    public const MAX_NODES = 256;

    /** Most stack entries processed in one walk (re-expansions included). */
    public const MAX_STEPS = 4096;

    /**
     * @return list<Throwable> The throwable first, then what it carries
     */
    public static function flatten(Throwable $throwable): array
    {
        /** @var array<int, int> $expandedAt shallowest depth each listed throwable was reached at */
        $expandedAt = [];
        $result     = [];
        /** @var list<array{Throwable, int}> $stack */
        $stack = [[$throwable, 0]];
        $steps = 0;

        while ($stack !== [] && count($result) < self::MAX_NODES && ++$steps <= self::MAX_STEPS) {
            [$current, $depth] = array_pop($stack);
            $id                = spl_object_id($current);

            if (isset($expandedAt[$id])) {
                if ($depth >= $expandedAt[$id]) {
                    continue;
                }
            } else {
                $result[] = $current;
            }

            $expandedAt[$id] = $depth;

            if ($depth >= self::MAX_DEPTH) {
                continue;
            }

            foreach (array_reverse(self::children($current)) as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }

        return $result;
    }

    /**
     * @return list<Throwable>
     */
    private static function children(Throwable $throwable): array
    {
        $children = [];
        $previous = $throwable->getPrevious();

        if ($previous instanceof Throwable) {
            $children[] = $previous;
        }

        if (interface_exists(WrappedExceptionsInterface::class) && $throwable instanceof WrappedExceptionsInterface) {
            foreach ($throwable->getWrappedExceptions() as $wrapped) {
                $children[] = $wrapped;
            }
        }

        return $children;
    }
}
