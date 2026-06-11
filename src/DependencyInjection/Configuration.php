<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree for nowo_doctrine_deadlock_retry (retry profiles).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
class Configuration implements ConfigurationInterface
{
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
                ->arrayNode('profiles')
                    ->info('Named retry profiles (max_retries, sleep_ms, rollback_on_deadlock)')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->integerNode('max_retries')
                                ->info('Number of retries after the first failed attempt (3 = up to 4 attempts)')
                                ->defaultValue(3)
                                ->min(0)
                            ->end()
                            ->integerNode('sleep_ms')
                                ->info('Milliseconds to wait between attempts')
                                ->defaultValue(100)
                                ->min(0)
                            ->end()
                            ->booleanNode('rollback_on_deadlock')
                                ->info('Rollback the active ORM transaction before retrying')
                                ->defaultTrue()
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([
                        'default' => [
                            'max_retries'          => 3,
                            'sleep_ms'             => 100,
                            'rollback_on_deadlock' => true,
                        ],
                    ])
                ->end()
            ->end();

        return $treeBuilder;
    }
}
