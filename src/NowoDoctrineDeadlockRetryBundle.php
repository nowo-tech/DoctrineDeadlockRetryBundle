<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle;

use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\Compiler\TransactionRunnerAliasPass;
use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\NowoDoctrineDeadlockRetryExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle that retries Doctrine operations when a DBAL deadlock occurs.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
class NowoDoctrineDeadlockRetryBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new TransactionRunnerAliasPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, TransactionRunnerAliasPass::PRIORITY);
    }

    /**
     * @return ExtensionInterface|null The bundle DI extension
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Symfony bundle extension lazy-init at boot; not request state
            $this->extension = new NowoDoctrineDeadlockRetryExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
