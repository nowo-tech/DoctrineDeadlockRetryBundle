<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Failure;

use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Throwable;

use function array_pop;
use function array_reverse;
use function array_slice;
use function array_values;
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

            foreach (array_reverse(self::children($current, self::MAX_NODES - count($result))) as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }

        return $result;
    }

    /**
     * Looks for a hit along the previous chain, where a wrapper (HandlerFailedException) only yields a hit when EVERY
     * exception it wraps yields one: a hit in one handler next to a plain failure in another is no hit.
     *
     * $test returns, for one throwable, a hit (stop, found), false (stop, nothing found) or null (go on with what it
     * carries). A wrapper whose $acceptWrapper returns false yields nothing; $pick chooses among the hits of the
     * wrapped exceptions (the first one by default). Same bounds as flatten(): MAX_DEPTH levels, MAX_NODES throwables
     * tested, and a throwable that is its own ancestor yields nothing.
     *
     * @param callable(Throwable): (false|Throwable|null) $test
     * @param (callable(Throwable): bool)|null $acceptWrapper
     * @param (callable(non-empty-list<Throwable>): Throwable)|null $pick
     */
    public static function resolve(Throwable $throwable, callable $test, ?callable $acceptWrapper = null, ?callable $pick = null): ?Throwable
    {
        $budget = self::MAX_NODES;

        return self::resolveFrom($throwable, 0, $test, $acceptWrapper, $pick, $budget, []);
    }

    /**
     * @param callable(Throwable): (false|Throwable|null) $test
     * @param (callable(Throwable): bool)|null $acceptWrapper
     * @param (callable(non-empty-list<Throwable>): Throwable)|null $pick
     * @param array<int, true> $ancestors
     */
    private static function resolveFrom(Throwable $throwable, int $depth, callable $test, ?callable $acceptWrapper, ?callable $pick, int &$budget, array $ancestors): ?Throwable
    {
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious(), ++$depth) {
            $id = spl_object_id($current);

            if ($depth > self::MAX_DEPTH || $budget <= 0 || isset($ancestors[$id])) {
                return null;
            }

            --$budget;
            $ancestors[$id] = true;
            $verdict        = $test($current);

            if ($verdict instanceof Throwable) {
                return $verdict;
            }

            if ($verdict === false) {
                return null;
            }

            $wrapped = self::wrapped($current);

            if ($wrapped === []) {
                continue;
            }

            // Every wrapped exception must be inspected: beyond the budget nothing can be proven.
            if (count($wrapped) > $budget || ($acceptWrapper !== null && !$acceptWrapper($current))) {
                return null;
            }

            $hits = [];

            foreach ($wrapped as $exception) {
                $hit = self::resolveFrom($exception, $depth + 1, $test, $acceptWrapper, $pick, $budget, $ancestors);

                if (!$hit instanceof Throwable) {
                    return null;
                }

                $hits[] = $hit;
            }

            return $pick !== null ? $pick($hits) : $hits[0];
        }

        return null;
    }

    /**
     * @param int $limit Most children returned (the remaining node budget)
     *
     * @return list<Throwable>
     */
    private static function children(Throwable $throwable, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $previous = $throwable->getPrevious();
        $children = $previous instanceof Throwable ? [$previous] : [];

        // HandlerFailedException's previous is also its first wrapped exception: take it once.
        foreach (self::wrapped($throwable, $limit + 1) as $wrapped) {
            if ($wrapped !== $previous) {
                $children[] = $wrapped;
            }
        }

        return array_slice($children, 0, $limit);
    }

    /**
     * @return list<Throwable> The exceptions a Messenger wrapper holds (empty for any other throwable)
     */
    private static function wrapped(Throwable $throwable, ?int $limit = null): array
    {
        if (!interface_exists(WrappedExceptionsInterface::class) || !$throwable instanceof WrappedExceptionsInterface) {
            return [];
        }

        $wrapped = array_values($throwable->getWrappedExceptions());

        return $limit === null ? $wrapped : array_slice($wrapped, 0, $limit);
    }
}
