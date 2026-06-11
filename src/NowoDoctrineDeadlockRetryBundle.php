<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle;

use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\NowoDoctrineDeadlockRetryExtension;
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
    /**
     * @return ExtensionInterface|null The bundle DI extension
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new NowoDoctrineDeadlockRetryExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
