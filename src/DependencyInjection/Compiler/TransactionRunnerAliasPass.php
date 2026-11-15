<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\Compiler;

use Nowo\TransactionContract\TransactionRunnerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Aliases nowo-tech/transaction-contract's TransactionRunnerInterface to this bundle's adapter only when nothing
 * defines it yet: a host's own alias always wins. Registered with a higher priority than the default (0) so that, when
 * bundles follow the adoption recipe (alias their one-attempt default only if absent), the retrying adapter wins.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransactionRunnerAliasPass implements CompilerPassInterface
{
    public const PRIORITY = 10;

    public function process(ContainerBuilder $container): void
    {
        if ($container->has(TransactionRunnerInterface::class) || !$container->has(NowoDoctrineDeadlockRetryServiceIds::TRANSACTION_RUNNER)) {
            return;
        }

        $container->setAlias(TransactionRunnerInterface::class, NowoDoctrineDeadlockRetryServiceIds::TRANSACTION_RUNNER);
    }
}
