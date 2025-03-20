<?php

namespace Phillarmonic\StaccacheBundle\Redis;

use Predis\Client as PredisClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Phillarmonic\StaccacheBundle\Redis\PhpRedisAdapter;
use Phillarmonic\StaccacheBundle\Redis\PredisAdapter;
use Phillarmonic\StaccacheBundle\Redis\SncRedisAdapter;
use Phillarmonic\StaccacheBundle\Redis\RedisClientInterface;

/**
 * Factory to create the appropriate Redis client adapter
 */
class StaccacheRedisFactory
{
    private static ?ContainerInterface $container = null;

    /**
     * Set the service container for accessing SNC Redis clients
     */
    public static function setContainer(ContainerInterface $container): void
    {
        self::$container = $container;
    }

    /**
     * Create a Redis client based on configuration
     */
    public static function createClient(array $config): RedisClientInterface
    {
        $driver = $config['driver'] ?? 'auto';

        // Check for SNC Redis client
        if (isset($config['snc_redis_client']) && !empty($config['snc_redis_client'])) {
            return self::createSncRedisClient($config['snc_redis_client']);
        }

        // Auto-detect available driver
        if ($driver === 'auto') {
            if (class_exists('\\Redis')) {
                $driver = 'phpredis';
            } elseif (class_exists('\\Predis\\Client')) {
                $driver = 'predis';
            } else {
                throw new \RuntimeException('No Redis driver found. Install phpredis extension or predis/predis package.');
            }
        }

        if ($driver === 'phpredis') {
            return self::createPhpRedisClient($config);
        } elseif ($driver === 'predis') {
            return self::createPredisClient($config);
        } else {
            throw new \InvalidArgumentException(sprintf('Unsupported Redis driver "%s"', $driver));
        }
    }

    /**
     * Create a raw Redis client for use with RedisStore
     * This returns the actual Redis or Predis\ClientInterface instance
     */
    public static function createRawClient(array $config)
    {
        $driver = $config['driver'] ?? 'auto';

        // Check for SNC Redis client
        if (isset($config['snc_redis_client']) && !empty($config['snc_redis_client'])) {
            if (self::$container === null) {
                throw new \RuntimeException('Container not set. Cannot retrieve SNC Redis client.');
            }
            $clientId = 'snc_redis.' . $config['snc_redis_client'];
            if (!self::$container->has($clientId)) {
                throw new \RuntimeException(sprintf('SNC Redis client "%s" not found', $config['snc_redis_client']));
            }
            return self::$container->get($clientId);
        }

        // Auto-detect available driver
        if ($driver === 'auto') {
            if (class_exists('\\Redis')) {
                $driver = 'phpredis';
            } elseif (class_exists('\\Predis\\Client')) {
                $driver = 'predis';
            } else {
                throw new \RuntimeException('No Redis driver found. Install phpredis extension or predis/predis package.');
            }
        }

        if ($driver === 'phpredis') {
            $client = new \Redis();

            $host = $config['host'] ?? 'localhost';
            $port = $config['port'] ?? 6379;
            $timeout = $config['options']['timeout'] ?? 0.0;
            $persistent = $config['options']['persistent'] ?? false;

            if ($persistent) {
                $connectionId = $config['options']['persistent_id'] ?? null;
                $client->pconnect($host, $port, $timeout, $connectionId);
            } else {
                $client->connect($host, $port, $timeout);
            }

            if (!empty($config['password'])) {
                $username = $config['username'] ?? null;
                if ($username !== null) {
                    // For Redis 6+ with ACL support
                    $client->auth([$username, $config['password']]);
                } else {
                    $client->auth($config['password']);
                }
            }

            if (isset($config['db'])) {
                $client->select($config['db']);
            }

            if (isset($config['options']['read_timeout'])) {
                $client->setOption(\Redis::OPT_READ_TIMEOUT, $config['options']['read_timeout']);
            }

            return $client;
        } elseif ($driver === 'predis') {
            $parameters = [];
            $options = [];

            // Build connection parameters
            $parameters['scheme'] = $config['scheme'] ?? 'tcp';
            $parameters['host'] = $config['host'] ?? 'localhost';
            $parameters['port'] = $config['port'] ?? 6379;

            if (!empty($config['password'])) {
                $parameters['password'] = $config['password'];
                if (!empty($config['username'])) {
                    $parameters['username'] = $config['username'];
                }
            }

            if (isset($config['db'])) {
                $parameters['database'] = $config['db'];
            }

            // Set options
            if (isset($config['options'])) {
                if (isset($config['options']['timeout'])) {
                    $options['timeout'] = $config['options']['timeout'];
                }

                if (isset($config['options']['read_timeout'])) {
                    $options['read_write_timeout'] = $config['options']['read_timeout'];
                }

                if (isset($config['options']['persistent']) && $config['options']['persistent']) {
                    $options['persistent'] = true;
                }
            }

            return new PredisClient($parameters, $options);
        } else {
            throw new \InvalidArgumentException(sprintf('Unsupported Redis driver "%s"', $driver));
        }
    }

    /**
     * Create PhpRedis client
     */
    private static function createPhpRedisClient(array $config): PhpRedisAdapter
    {
        $client = new \Redis();

        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 6379;
        $timeout = $config['options']['timeout'] ?? 0.0;
        $persistent = $config['options']['persistent'] ?? false;

        if ($persistent) {
            $connectionId = $config['options']['persistent_id'] ?? null;
            $client->pconnect($host, $port, $timeout, $connectionId);
        } else {
            $client->connect($host, $port, $timeout);
        }

        if (!empty($config['password'])) {
            $username = $config['username'] ?? null;
            if ($username !== null) {
                // For Redis 6+ with ACL support
                $client->auth([$username, $config['password']]);
            } else {
                $client->auth($config['password']);
            }
        }

        if (isset($config['db'])) {
            $client->select($config['db']);
        }

        if (isset($config['options']['read_timeout'])) {
            $client->setOption(\Redis::OPT_READ_TIMEOUT, $config['options']['read_timeout']);
        }

        return new PhpRedisAdapter($client);
    }

    /**
     * Create SNC Redis client adapter
     */
    private static function createSncRedisClient(string $clientName): SncRedisAdapter
    {
        if (self::$container === null) {
            throw new \RuntimeException('Container not set. Cannot retrieve SNC Redis client.');
        }

        $clientId = 'snc_redis.' . $clientName;
        if (!self::$container->has($clientId)) {
            throw new \RuntimeException(sprintf('SNC Redis client "%s" not found', $clientName));
        }

        $client = self::$container->get($clientId);
        return new SncRedisAdapter($client);
    }

    /**
     * Create Predis client
     */
    private static function createPredisClient(array $config): PredisAdapter
    {
        $parameters = [];
        $options = [];

        // Build connection parameters
        $parameters['scheme'] = $config['scheme'] ?? 'tcp';
        $parameters['host'] = $config['host'] ?? 'localhost';
        $parameters['port'] = $config['port'] ?? 6379;

        if (!empty($config['password'])) {
            $parameters['password'] = $config['password'];
            if (!empty($config['username'])) {
                $parameters['username'] = $config['username'];
            }
        }

        if (isset($config['db'])) {
            $parameters['database'] = $config['db'];
        }

        // Set options
        if (isset($config['options'])) {
            if (isset($config['options']['timeout'])) {
                $options['timeout'] = $config['options']['timeout'];
            }

            if (isset($config['options']['read_timeout'])) {
                $options['read_write_timeout'] = $config['options']['read_timeout'];
            }

            if (isset($config['options']['persistent']) && $config['options']['persistent']) {
                $options['persistent'] = true;
            }
        }

        $client = new PredisClient($parameters, $options);
        return new PredisAdapter($client);
    }
}