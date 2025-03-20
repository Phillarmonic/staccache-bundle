<?php

namespace Phillarmonic\StaccacheBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('staccache');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->integerNode('default_ttl')
                    ->info('Default time-to-live for cached entities in seconds')
                    ->defaultValue(3600) // 1 hour
                ->end()
                ->integerNode('lock_ttl')
                    ->info('Default time-to-live for entity locks in seconds')
                    ->defaultValue(30) // 30 seconds
                ->end()
                ->scalarNode('cache_prefix')
                    ->info('Prefix for cache keys')
                    ->defaultValue('staccache')
                ->end()
                ->scalarNode('entity_namespace')
                    ->info('Namespace for cacheable entities (optional filter)')
                    ->defaultNull()
                ->end()
                ->scalarNode('secret_key')
                    ->info('Secret key used for integrity verification (defaults to a value based on installation path if not set)')
                    ->defaultValue('')
                ->end()
                ->booleanNode('auto_cache_on_load')
                    ->info('Whether to automatically cache entities when they are loaded')
                    ->defaultTrue()
                ->end()
                                ->arrayNode('redis')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('snc_redis_client')
                            ->defaultNull()
                            ->info('SNC Redis client name to use (e.g., "default" for snc_redis.default service)')
                        ->end()
                        ->enumNode('driver')
                            ->values(['auto', 'phpredis', 'predis'])
                            ->defaultValue('auto')
                            ->info('Redis driver to use: auto (detect), phpredis (extension), or predis (library)')
                        ->end()
                        ->scalarNode('scheme')
                            ->defaultValue('tcp')
                        ->end()
                        ->scalarNode('host')
                            ->defaultValue('localhost')
                        ->end()
                        ->integerNode('port')
                            ->defaultValue(6379)
                        ->end()
                        ->scalarNode('username')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('password')
                            ->defaultNull()
                        ->end()
                        ->integerNode('db')
                            ->defaultValue(0)
                        ->end()
                        ->arrayNode('options')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->floatNode('timeout')
                                    ->defaultValue(5.0)
                                ->end()
                                ->floatNode('read_timeout')
                                    ->defaultValue(5.0)
                                ->end()
                                ->booleanNode('persistent')
                                    ->defaultFalse()
                                ->end()
                                ->scalarNode('persistent_id')
                                    ->defaultNull()
                                    ->info('Persistent connection ID for phpredis')
                                ->end()
                            ->end()
                        ->end()
            ->end();

        return $treeBuilder;
    }
}