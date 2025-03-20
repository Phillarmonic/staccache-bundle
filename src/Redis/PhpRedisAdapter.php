<?php

namespace Phillarmonic\StaccacheBundle\Redis;

/**
 * Adapter for PhpRedis client
 */
class PhpRedisAdapter implements RedisClientInterface
{
    private \Redis $client;

    public function __construct(\Redis $client)
    {
        $this->client = $client;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key)
    {
        return $this->client->get($key);
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, $value)
    {
        return $this->client->set($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function expire(string $key, int $ttl)
    {
        return $this->client->expire($key, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function del(string ...$keys)
    {
        return $this->client->del(...$keys);
    }

    /**
     * {@inheritdoc}
     */
    public function keys(string $pattern): array
    {
        $keys = $this->client->keys($pattern);
        // Ensure we always return an array, even if keys() returns false
        return is_array($keys) ? $keys : [];
    }

    /**
     * {@inheritdoc}
     */
    public function scan(&$iterator, string $pattern, int $count = 10)
    {
        return $this->client->scan($iterator, $pattern, $count);
    }
}