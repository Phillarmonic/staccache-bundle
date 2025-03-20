<?php

namespace Phillarmonic\StaccacheBundle\Cache;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Phillarmonic\StaccacheBundle\Redis\RedisClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages caching for custom queries
 */
class QueryCacheManager
{
    private RedisClientInterface $redis;
    private EntityCacheManager $entityCacheManager;
    private ManagerRegistry $doctrine;
    private int $defaultQueryTtl;
    private string $cachePrefix;
    private string $secretKey;

    public function __construct(
        RedisClientInterface $redis,
        EntityCacheManager $entityCacheManager,
        ManagerRegistry $doctrine,
        int $defaultQueryTtl,
        string $cachePrefix,
        string $secretKey = '',
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->redis = $redis;
        $this->entityCacheManager = $entityCacheManager;
        $this->doctrine = $doctrine;
        $this->defaultQueryTtl = $defaultQueryTtl;
        $this->cachePrefix = $cachePrefix;
        $this->secretKey = $secretKey ?: md5(__DIR__);
    }

    /**
     * Cache the result of a query
     *
     * @param string $queryKey Unique identifier for the query
     * @param array $result The query result to cache
     * @param string $entityClass The entity class of the result items
     * @param int|null $ttl Cache TTL in seconds (null to use default)
     * @return void
     */
    public function cacheQueryResult(string $queryKey, array $result, string $entityClass, ?int $ttl = null): void
    {
        try {
            if (empty($result)) {
                return;
            }

            // Cache individual entities first
            $entityIds = [];
            foreach ($result as $entity) {
                if ($this->entityCacheManager->isCacheable($entity)) {
                    $this->entityCacheManager->cacheEntity($entity);

                    // Get entity manager and metadata
                    $entityManager = $this->doctrine->getManagerForClass(get_class($entity));
                    if ($entityManager) {
                        $metadata = $entityManager->getClassMetadata(get_class($entity));
                        $id = $this->getEntityId($entity, $metadata);

                        if ($id) {
                            $entityIds[] = $id;
                        }
                    }
                }
            }

            // Create cache key
            $cacheKey = $this->createQueryCacheKey($queryKey, $entityClass);

            // Store only the entity IDs and metadata in cache
            $cacheData = json_encode([
                'ids' => $entityIds,
                'class' => $entityClass,
                'timestamp' => time(),
                'hash' => $this->calculateQueryResultHash($entityIds, $entityClass, $queryKey),
            ], JSON_THROW_ON_ERROR);

            // Use provided TTL or fall back to default
            $effectiveTtl = $ttl ?? $this->defaultQueryTtl;

            // Store in Redis
            $this->redis->set($cacheKey, $cacheData);
            $this->redis->expire($cacheKey, $effectiveTtl);

            $this->logger?->debug("Cached query result for key: {$cacheKey} with TTL: {$effectiveTtl}");
        } catch (\Throwable $e) {
            $this->logger?->error('Error caching query result: ' . $e->getMessage());
        }
    }

    /**
     * Get cached query result
     *
     * @param string $queryKey Unique identifier for the query
     * @param string $entityClass The entity class of the expected result
     * @return array|null The cached result or null if not found
     */
    public function getCachedQueryResult(string $queryKey, string $entityClass): ?array
    {
        try {
            // Create cache key
            $cacheKey = $this->createQueryCacheKey($queryKey, $entityClass);

            // Check if query result exists in cache
            $cachedData = $this->redis->get($cacheKey);
            if (!$cachedData) {
                return null;
            }

            // Decode the cached wrapper
            $cachedWrapper = json_decode($cachedData, true);
            if (!isset($cachedWrapper['ids']) || !isset($cachedWrapper['class']) || !isset($cachedWrapper['hash'])) {
                // Invalid format, invalidate the cache
                $this->redis->del($cacheKey);
                return null;
            }

            // Verify the expected class
            if ($cachedWrapper['class'] !== $entityClass) {
                // Class mismatch, invalidate the cache
                $this->redis->del($cacheKey);
                return null;
            }

            // Verify integrity
            $entityIds = $cachedWrapper['ids'];
            $calculatedHash = $this->calculateQueryResultHash($entityIds, $entityClass, $queryKey);
            if (!hash_equals($calculatedHash, $cachedWrapper['hash'])) {
                // Integrity check failed, invalidate the cache
                $this->redis->del($cacheKey);
                return null;
            }

            // Get entity manager
            $em = $this->doctrine->getManagerForClass($entityClass);
            if (!$em) {
                $this->logger?->error("No entity manager found for class: {$entityClass}");
                return null;
            }

            // Load individual entities from cache
            $result = [];
            foreach ($entityIds as $id) {
                $entity = $this->entityCacheManager->getFromCache($entityClass, (string)$id, $em);

                // If entity is not in cache, fetch it from database
                if ($entity === null) {
                    $entity = $em->getRepository($entityClass)->find($id);

                    // If still null, skip this entity
                    if ($entity === null) {
                        continue;
                    }

                    // Cache the entity
                    $this->entityCacheManager->cacheEntity($entity);
                }

                // Add to result
                $result[] = $entity;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger?->error('Error getting cached query result: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Invalidate a cached query result
     *
     * @param string $queryKey Unique identifier for the query
     * @param string $entityClass The entity class of the result
     * @return void
     */
    public function invalidateQueryCache(string $queryKey, string $entityClass): void
    {
        try {
            $cacheKey = $this->createQueryCacheKey($queryKey, $entityClass);
            $this->redis->del($cacheKey);
            $this->logger?->debug("Invalidated query cache for key: {$cacheKey}");
        } catch (\Throwable $e) {
            $this->logger?->error('Error invalidating query cache: ' . $e->getMessage());
        }
    }

    /**
     * Invalidate all query caches for an entity class
     *
     * @param string $entityClass The entity class
     * @return void
     */
    public function invalidateEntityQueryCaches(string $entityClass): void
    {
        try {
            /**
             * Redis requires escaping slashes, so a class that could be App\Something
             * becomes App\\Something
             * Or the search will not return any result.
             */
            $escapedEntityClass = str_replace('\\', '\\\\', $entityClass);
            $pattern = $this->cachePrefix . ':query:' . $escapedEntityClass . ':*';
            $keys = $this->scanKeys($pattern);

            if (!empty($keys)) {
                $deleted = $this->redis->del(...$keys);
                $this->logger?->debug("Deleted {$deleted} query cache keys for entity class: {$entityClass}");
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Error invalidating entity query caches: ' . $e->getMessage());
        }
    }

    /**
     * Create a cache key for a query
     *
     * @param string $queryKey Unique identifier for the query
     * @param string $entityClass The entity class of the result
     * @return string The cache key
     */
    private function createQueryCacheKey(string $queryKey, string $entityClass): string
    {
        return sprintf('%s:query:%s:%s', $this->cachePrefix, $entityClass, md5($queryKey));
    }

    /**
     * Calculate hash for query result integrity check
     *
     * @param array $entityIds The entity IDs in the result
     * @param string $entityClass The entity class
     * @param string $queryKey The query key
     * @return string The hash
     */
    private function calculateQueryResultHash(array $entityIds, string $entityClass, string $queryKey): string
    {
        $data = $entityClass . ':' . $queryKey . ':' . implode(',', $entityIds);
        return hash_hmac('sha256', $data, $this->secretKey);
    }

    /**
     * Get entity ID
     *
     * @param object $entity The entity
     * @param mixed $metadata The entity metadata
     * @return string|null The entity ID
     */
    private function getEntityId(object $entity, $metadata): ?string
    {
        $ids = $metadata->getIdentifierValues($entity);

        if (empty($ids)) {
            return null;
        }

        // For composite keys, create a hash
        if (count($ids) > 1) {
            return md5(serialize($ids));
        }

        return (string) reset($ids);
    }

    /**
     * Scan Redis for keys matching a pattern
     *
     * @param string $pattern The pattern
     * @return array The matching keys
     */
    private function scanKeys(string $pattern): array
    {
        $keys = [];

        try {
            // Try direct KEYS command first
            try {
                $directKeys = $this->redis->keys($pattern);
                if (is_array($directKeys) && !empty($directKeys)) {
                    return $directKeys;
                }
            } catch (\Throwable $e) {
                $this->logger?->error("Direct KEYS command failed: " . $e->getMessage() . " - falling back to SCAN");
            }

            // Fall back to SCAN
            $iterator = null;
            $scanKeys = $this->redis->scan($iterator, $pattern, 100);

            while ($scanKeys && !empty($scanKeys)) {
                array_push($keys, ...$scanKeys);

                if ($iterator === 0 || $iterator === false) {
                    break;
                }

                $scanKeys = $this->redis->scan($iterator, $pattern, 100);
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Error in scanKeys: ' . $e->getMessage());
        }

        return is_array($keys) ? $keys : [];
    }
}