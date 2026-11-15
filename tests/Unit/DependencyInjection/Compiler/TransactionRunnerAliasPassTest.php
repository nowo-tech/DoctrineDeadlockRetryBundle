<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\Compiler\NowoDoctrineDeadlockRetryServiceIds;
use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\Compiler\TransactionRunnerAliasPass;
use Nowo\DoctrineDeadlockRetryBundle\NowoDoctrineDeadlockRetryBundle;
use Nowo\TransactionContract\TransactionRunnerInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransactionRunnerAliasPassTest extends TestCase
{
    public function testAliasesTheInterfaceWhenNothingDefinesIt(): void
    {
        $container = new ContainerBuilder();
        $container->register(NowoDoctrineDeadlockRetryServiceIds::TRANSACTION_RUNNER, stdClass::class);

        (new TransactionRunnerAliasPass())->process($container);

        self::assertSame(NowoDoctrineDeadlockRetryServiceIds::TRANSACTION_RUNNER, (string) $container->getAlias(TransactionRunnerInterface::class));
    }

    public function testAnExistingDefinitionWins(): void
    {
        $container = new ContainerBuilder();
        $container->register(NowoDoctrineDeadlockRetryServiceIds::TRANSACTION_RUNNER, stdClass::class);
        $container->register('app.runner', stdClass::class);
        $container->setAlias(TransactionRunnerInterface::class, 'app.runner');

        (new TransactionRunnerAliasPass())->process($container);

        self::assertSame('app.runner', (string) $container->getAlias(TransactionRunnerInterface::class));
    }

    public function testDoesNothingWithoutTheAdapter(): void
    {
        $container = new ContainerBuilder();

        (new TransactionRunnerAliasPass())->process($container);

        self::assertFalse($container->has(TransactionRunnerInterface::class));
    }

    public function testTheBundleRegistersThePassBeforeDefaultPriorityPasses(): void
    {
        $container = new ContainerBuilder();
        (new NowoDoctrineDeadlockRetryBundle())->build($container);

        $found = false;

        foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $pass) {
            $found = $found || $pass instanceof TransactionRunnerAliasPass;
        }

        self::assertTrue($found);
    }
}
