<?php

namespace Phillarmonic\StaccacheBundle\Redis;

/**
 * Interface for Redis clients
 */
interface RedisClientInterface
{
    /**
     * Get a value from Redis
     */
    public function get(string $key);

    /**
     * Set a value in Redis
     */
    public function set(string $key, $value);

    /**
     * Set expiration on a key
     */
    public function expire(string $key, int $ttl);

    /**
     * Delete a key
     */
    public function del(string ...$keys);

    /**
     * Find all keys matching a pattern
     */
    public function keys(string $pattern): array;

    /**
     * Incrementally iterate through keys matching a pattern
     *
     * @param int|null &$iterator The iterator to use for scan (modified by reference)
     * @param string $pattern The pattern to match
     * @param int $count The count of keys to return per iteration
     * @return array|false The found keys or false if no more keys
     */
    public function scan(&$iterator, string $pattern, int $count = 10);
}