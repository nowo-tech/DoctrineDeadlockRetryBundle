<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\MessengerWorkerGuardSubscriber;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\TransientFailureExceptionListener;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\WorkerGuardSubscriber;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureClassifier;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

use function array_values;
use function class_exists;
use function is_int;
use function is_string;
use function max;
use function sprintf;

/**
 * Loads services, builds retry profiles and registers the opt-in worker guard and HTTP mapping.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoDoctrineDeadlockRetryExtension extends Extension
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
            $baseMs    = $profileConfig['backoff_base_ms'] ?? $profileConfig['sleep_ms'] ?? Configuration::DEFAULT_BACKOFF_BASE_MS;
            $baseMs    = is_int($baseMs) ? $baseMs : Configuration::DEFAULT_BACKOFF_BASE_MS;

            $container
                ->register($profileId, RetryProfile::class)
                ->setArguments([
                    (string) $name,
                    (int) $profileConfig['max_retries'],
                    $baseMs,
                    (bool) $profileConfig['rollback_on_deadlock'],
                    max($baseMs, (int) $profileConfig['backoff_max_ms']),
                    $profileConfig['jitter'] === 'full',
                    (bool) $profileConfig['retry_lock_wait_timeout'],
                    is_int($profileConfig['max_total_ms'] ?? null) ? $profileConfig['max_total_ms'] : null,
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

        $entityManagerName = $config['entity_manager'];
        if (is_string($entityManagerName)) {
            $definition->setArgument('$entityManager', new Reference(sprintf('doctrine.orm.%s_entity_manager', $entityManagerName)));
            $definition->setArgument('$entityManagerName', $entityManagerName);
        }

        $this->registerWorkerGuard($config['worker_guard'], $container);
        $this->registerHttpMapping($config['http_mapping'], (int) $config['retry_after_seconds'], $container);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerWorkerGuard(array $config, ContainerBuilder $container): void
    {
        if (!($config['enabled'] ?? false)) {
            return;
        }

        $container
            ->register(ConnectionGuard::class, ConnectionGuard::class)
            ->setArguments([
                '$registry'            => new Reference('doctrine'),
                '$logger'              => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$connections'         => array_values((array) $config['connections']),
                '$resetClosedManagers' => (bool) $config['reset_closed_managers'],
                '$clearManagers'       => (bool) $config['clear_managers'],
                '$container'           => new Reference('service_container'),
            ])
            ->setPublic(false);

        $container
            ->register(WorkerGuardSubscriber::class, WorkerGuardSubscriber::class)
            ->setArguments([new Reference(ConnectionGuard::class)])
            ->addTag('kernel.event_subscriber');

        if (($config['messenger'] ?? false) && class_exists(WorkerMessageHandledEvent::class)) {
            $container
                ->register(MessengerWorkerGuardSubscriber::class, MessengerWorkerGuardSubscriber::class)
                ->setArguments([new Reference(ConnectionGuard::class)])
                ->addTag('kernel.event_subscriber');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerHttpMapping(array $config, int $retryAfterSeconds, ContainerBuilder $container): void
    {
        if (!($config['enabled'] ?? false)) {
            return;
        }

        $container
            ->register(TransientFailureExceptionListener::class, TransientFailureExceptionListener::class)
            ->setArguments([
                '$retryAfterSeconds'  => $retryAfterSeconds,
                '$pathPrefixes'       => array_values((array) $config['paths']),
                '$routePrefixes'      => array_values((array) $config['routes']),
                '$conflictExceptions' => array_values((array) $config['conflict_exceptions']),
                '$format'             => (string) $config['format'],
                '$classifier'         => new Reference(TransientFailureClassifier::class),
            ])
            ->addTag('kernel.event_subscriber');
    }

    /**
     * @return string Extension alias (nowo_doctrine_deadlock_retry)
     */
    public function getAlias(): string
    {
        return 'nowo_doctrine_deadlock_retry';
    }
}
