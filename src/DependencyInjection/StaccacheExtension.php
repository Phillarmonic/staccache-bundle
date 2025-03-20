<?php

namespace Phillarmonic\StaccacheBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Psr\Log\NullLogger;

class StaccacheExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $this->configureRedisConnection($container, $config);
        $this->configureCacheSettings($container, $config);
        $this->configureLogger($container);
    }

    private function configureRedisConnection(ContainerBuilder $container, array $config)
    {
        $container->setParameter('staccache.redis_config', $config['redis']);
    }

    private function configureCacheSettings(ContainerBuilder $container, array $config)
    {
        $container->setParameter('staccache.default_ttl', $config['default_ttl']);
        $container->setParameter('staccache.lock_ttl', $config['lock_ttl']);
        $container->setParameter('staccache.entity_namespace', $config['entity_namespace']);
        $container->setParameter('staccache.cache_prefix', $config['cache_prefix']);
        $container->setParameter('staccache.auto_cache_on_load', $config['auto_cache_on_load']);
        $container->setParameter('staccache.secret_key', $config['secret_key']);
    }

    private function configureLogger(ContainerBuilder $container)
    {
        // Remove any existing logger definition that might be in the YAML file
        if ($container->hasDefinition('staccache.logger')) {
            $container->removeDefinition('staccache.logger');
        }

        // Try to use monolog's dedicated channel if available
        if (class_exists('Symfony\Bundle\MonologBundle\MonologBundle') && $container->hasParameter('kernel.container_class')) {
            // Create a reference to monolog's channel
            $loggerDefinition = new Definition('Psr\Log\LoggerInterface');

            // First try monolog.logger.staccache specific logger
            if ($container->has('monolog.logger.staccache')) {
                $loggerDefinition->setFactory([new Reference('monolog.logger.staccache'), 'getLogger']);
            }
            // Then try the main logger with channel
            else if ($container->has('logger')) {
                $loggerDefinition->setFactory([new Reference('logger'), 'withContext']);
                $loggerDefinition->addArgument(['channel' => 'staccache']);
            }
            // Fall back to NullLogger if neither option is available
            else {
                $loggerDefinition = new Definition(NullLogger::class);
            }

            $container->setDefinition('staccache.logger', $loggerDefinition);
        } else {
            // If MonologBundle is not available, use NullLogger
            $container->register('staccache.logger', NullLogger::class);
        }
    }

    public function getAlias(): string
    {
        return 'staccache';
    }
}