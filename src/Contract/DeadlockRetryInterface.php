<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Contract;

/**
 * Public contract of the deadlock-retry service (REQ-ARCH-001 rule 1).
 *
 * Type-hint this interface, not the final DeadlockRetryService. A host may replace the implementation by
 * re-aliasing it. The interface carries no Doctrine type (rule 3): the concrete service additionally offers
 * getEntityManager() for standard Symfony hosts.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface DeadlockRetryInterface
{
    /**
     * Flushes the unit of work, retrying on deadlock using the given or default profile.
     *
     * @param string|null $profile Optional profile name; the configured default when null
     */
    public function flush(?string $profile = null): void;

    /**
     * Runs a callable with deadlock retries; the callable redoes its whole unit of work on each attempt.
     *
     * @template T
     *
     * @param callable(): T $operation Callable that performs the work
     * @param string|null $profile Optional profile name; the default when null
     *
     * @return T
     */
    public function retry(callable $operation, ?string $profile = null): mixed;

    /**
     * @return list<string> Configured profile names
     */
    public function getProfileNames(): array;

    /**
     * @return string Name of the profile used when none is passed to flush() or retry()
     */
    public function getDefaultProfileName(): string;
}
