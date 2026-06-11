<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/**
 * Loads services and builds retry profiles from configuration.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
class NowoDoctrineDeadlockRetryExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $profileReferences = [];

        foreach ($config['profiles'] as $name => $profileConfig) {
            $profileId = sprintf('nowo_doctrine_deadlock_retry.profile.%s', $name);

            $container
                ->register($profileId, RetryProfile::class)
                ->setArguments([
                    (string) $name,
                    (int) $profileConfig['max_retries'],
                    (int) $profileConfig['sleep_ms'],
                    (bool) $profileConfig['rollback_on_deadlock'],
                ])
                ->setPublic(false);

            $profileReferences[$name] = new Reference($profileId);
        }

        $defaultProfile = (string) $config['default_profile'];

        if (!isset($profileReferences[$defaultProfile])) {
            throw new InvalidArgumentException(sprintf('The default_profile "%s" is not defined in nowo_doctrine_deadlock_retry.profiles.', $defaultProfile));
        }

        $definition = $container->getDefinition(DeadlockRetryService::class);
        $definition->setArgument('$profiles', $profileReferences);
        $definition->setArgument('$defaultProfile', $defaultProfile);
    }

    /**
     * @return string Extension alias (nowo_doctrine_deadlock_retry)
     */
    public function getAlias(): string
    {
        return 'nowo_doctrine_deadlock_retry';
    }
}
