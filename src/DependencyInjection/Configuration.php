<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\DependencyInjection;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\TransientFailureExceptionListener;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function is_array;
use function is_int;
use function is_string;

/**
 * Configuration tree for nowo_doctrine_deadlock_retry: retry profiles, the worker guard and the HTTP mapping.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class Configuration implements ConfigurationInterface
{
    /** Backoff base used when neither backoff_base_ms nor its alias sleep_ms is set. */
    public const DEFAULT_BACKOFF_BASE_MS = 100;

    /**
     * @return TreeBuilder Configuration tree for nowo_doctrine_deadlock_retry
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('nowo_doctrine_deadlock_retry');
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('default_profile')
                    ->info('Name of the profile used when none is passed to flush() or retry()')
                    ->defaultValue('default')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('entity_manager')
                    ->info('Doctrine entity manager name (doctrine.orm.entity_managers key) the service flushes and resets; null = default entity manager')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || $value === ''))
                        ->thenInvalid('entity_manager must be null or a non-empty entity manager name.')
                    ->end()
                ->end()
                ->integerNode('retry_after_seconds')
                    ->info('Retry-After hint (seconds) of a 503 from http_mapping')
                    ->defaultValue(1)
                    ->min(0)
                ->end()
                ->append($this->profilesNode())
                ->append($this->workerGuardNode())
                ->append($this->httpMappingNode())
            ->end();

        return $treeBuilder;
    }

    private function profilesNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('profiles'))->getRootNode();

        $node
            ->info('Named retry profiles')
            ->requiresAtLeastOneElement()
            ->useAttributeAsKey('name')
            ->arrayPrototype()
                ->validate()
                    ->ifTrue(static fn (mixed $profile): bool => is_array($profile)
                        && is_int($profile['sleep_ms'] ?? null)
                        && is_int($profile['backoff_base_ms'] ?? null)
                        && $profile['sleep_ms'] !== $profile['backoff_base_ms'])
                    ->thenInvalid('sleep_ms is an alias of backoff_base_ms: set only one of them (or the same value).')
                ->end()
                ->children()
                    ->integerNode('max_retries')
                        ->info('Number of retries after the first failed attempt (3 = up to 4 attempts)')
                        ->defaultValue(3)
                        ->min(0)
                    ->end()
                    ->integerNode('backoff_base_ms')
                        ->info('Backoff base in milliseconds: the delay before retry n is at most min(backoff_max_ms, base × 2^(n-1)); default ' . self::DEFAULT_BACKOFF_BASE_MS)
                        ->defaultNull()
                        ->min(0)
                    ->end()
                    ->integerNode('sleep_ms')
                        ->info('Alias of backoff_base_ms (kept for configurations written for 2.x)')
                        ->defaultNull()
                        ->min(0)
                    ->end()
                    ->integerNode('backoff_max_ms')
                        ->info('Upper bound of a single delay in milliseconds (never below the base)')
                        ->defaultValue(RetryProfile::DEFAULT_BACKOFF_MAX_MS)
                        ->min(0)
                    ->end()
                    ->enumNode('jitter')
                        ->info('full: each delay is random between 0 and the bound (no lockstep retries); none: exactly the bound')
                        ->values(['full', 'none'])
                        ->defaultValue('full')
                    ->end()
                    ->booleanNode('retry_lock_wait_timeout')
                        ->info('Also retry lock wait timeouts (MySQL / MariaDB 1205); each attempt may wait innodb_lock_wait_timeout')
                        ->defaultTrue()
                    ->end()
                    ->booleanNode('rollback_on_deadlock')
                        ->info('Repair the connection after a failure: close it when a transaction is left active, roll back inside a caller\'s transaction')
                        ->defaultTrue()
                    ->end()
                ->end()
            ->end()
            ->defaultValue([
                'default' => [
                    'max_retries'             => 3,
                    'backoff_base_ms'         => self::DEFAULT_BACKOFF_BASE_MS,
                    'sleep_ms'                => null,
                    'backoff_max_ms'          => RetryProfile::DEFAULT_BACKOFF_MAX_MS,
                    'jitter'                  => 'full',
                    'retry_lock_wait_timeout' => true,
                    'rollback_on_deadlock'    => true,
                ],
            ]);

        return $node;
    }

    private function workerGuardNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('worker_guard'))->getRootNode();

        $node
            ->info('Repairs connections left in a transaction and closed entity managers on kernel.request and kernel.terminate (long-running workers)')
            ->canBeEnabled()
            ->children()
                ->arrayNode('connections')
                    ->info('Connection names to guard; empty = every Doctrine connection')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->booleanNode('reset_closed_managers')
                    ->info('Also reset closed entity managers of every manager in the registry')
                    ->defaultTrue()
                ->end()
            ->end();

        return $node;
    }

    private function httpMappingNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('http_mapping'))->getRootNode();

        $node
            ->info('Maps transient failures that reach the kernel to 503 + Retry-After and conflicts to 409, instead of 500')
            ->canBeEnabled()
            ->children()
                ->arrayNode('paths')
                    ->info('Request path prefixes handled (e.g. /api); with routes empty too, every request')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('routes')
                    ->info('Route name prefixes handled (e.g. api_)')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('conflict_exceptions')
                    ->info('Exception classes or interfaces mapped to 409 Conflict (searched along the previous chain)')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([OptimisticLockException::class, UniqueConstraintViolationException::class])
                ->end()
                ->enumNode('format')
                    ->info('exception: hand an HttpException to the host\'s error rendering (keeps its problem+json); problem_json: answer application/problem+json directly')
                    ->values([TransientFailureExceptionListener::FORMAT_EXCEPTION, TransientFailureExceptionListener::FORMAT_PROBLEM_JSON])
                    ->defaultValue(TransientFailureExceptionListener::FORMAT_EXCEPTION)
                ->end()
            ->end();

        return $node;
    }
}
