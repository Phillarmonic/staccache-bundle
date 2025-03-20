<?php

namespace Phillarmonic\StaccacheBundle\Redis;

/**
 * Adapter for SncRedisBundle client
 */
class SncRedisAdapter implements RedisClientInterface
{
    private $client;

    public function __construct($client)
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
        return is_array($keys) ? $keys : [];
    }

    /**
     * {@inheritdoc}
     */
    public function scan(&$iterator, string $pattern, int $count = 10)
    {
        // SNC Redis can wrap either PhpRedis or Predis, so we need to handle both
        try {
            if (method_exists($this->client, 'getRedis')) {
                // This is likely a PhpRedis client
                $innerClient = $this->client->getRedis();
                return $innerClient->scan($iterator, $pattern, $count);
            } else if (method_exists($this->client, 'scan')) {
                // Direct scan support (probably Predis)
                $response = $this->client->scan($iterator, ['MATCH' => $pattern, 'COUNT' => $count]);

                // Handle Predis-style response
                if (is_array($response) && count($response) === 2) {
                    $iterator = $response[0];
                    return $response[1];
                }

                // Handle PhpRedis-style response
                return $response;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error in SncRedisAdapter::scan: ' . $e->getMessage());
        }

        // Fallback: if scan fails, try to get keys all at once
        if ($iterator === null || $iterator === 0) {
            // Only try keys() on the first iteration
            $iterator = false; // Mark as complete after this attempt
            return $this->keys($pattern);
        }

        return false; // No more keys
    }
}