<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\Compiler;

/**
 * Service ids owned by this bundle.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoDoctrineDeadlockRetryServiceIds
{
    /** The TransactionRunnerInterface adapter (DeadlockRetryTransactionRunner). */
    public const TRANSACTION_RUNNER = 'nowo_doctrine_deadlock_retry.transaction_runner';
}
