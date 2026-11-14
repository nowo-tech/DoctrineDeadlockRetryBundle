<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Application;

/**
 * The host's own transaction port (application layer). Framework-free: no Doctrine, no bundle type.
 */
interface TransactionRunner
{
    /**
     * @template T
     *
     * @param callable(): T $work The whole unit of work; it may run more than once
     *
     * @return T
     */
    public function run(callable $work): mixed;
}
