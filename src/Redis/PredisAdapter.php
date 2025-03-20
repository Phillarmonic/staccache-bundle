<?php

namespace Phillarmonic\StaccacheBundle\Redis;

use Predis\Client as PredisClient;

/**
 * Adapter for Predis client
 */
class PredisAdapter implements RedisClientInterface
{
    private PredisClient $client;

    public function __construct(PredisClient $client)
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
        $result = $this->client->keys($pattern);
        // Ensure we always return an array
        return is_array($result) ? $result : [];
    }

    /**
     * {@inheritdoc}
     */
    public function scan(&$iterator, string $pattern, int $count = 10)
    {
        // Predis has a different scan implementation than phpredis
        // The response contains both an iterator and the keys
        $response = $this->client->scan($iterator, ['MATCH' => $pattern, 'COUNT' => $count]);

        // Update iterator by reference
        $iterator = $response[0];

        // Return keys
        return $response[1];
    }
}