<?php

namespace Phillarmonic\StaccacheBundle\Cache;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Phillarmonic\StaccacheBundle\Attribute\Staccacheable;
use Phillarmonic\StaccacheBundle\Redis\RedisClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Manages entity caching and serialization
 */
class EntityCacheManager
{
    private RedisClientInterface $redis;
    private LockFactory $lockFactory;
    private SerializerInterface $serializer;
    private ManagerRegistry $doctrine;
    private int $defaultTtl;
    private int $lockTtl;
    private string $cachePrefix;
    private string $secretKey;
    private array $locks = [];
    private array $entityConfigs = [];

    public function __construct(
        RedisClientInterface $redis,
        LockFactory $lockFactory,
        SerializerInterface $serializer,
        ManagerRegistry $doctrine,
        int $defaultTtl,
        int $lockTtl,
        string $cachePrefix,
        string $secretKey = '',
        private readonly ?LoggerInterface $logger
    ) {
        $this->redis = $redis;
        $this->lockFactory = $lockFactory;
        $this->serializer = $serializer;
        $this->doctrine = $doctrine;
        $this->defaultTtl = $defaultTtl;
        $this->lockTtl = $lockTtl;
        $this->cachePrefix = $cachePrefix;
        $this->secretKey = $secretKey ?: md5(__DIR__);
    }

    /**
     * Check if an entity is cacheable
     */
    public function isCacheable(object $entity): bool
    {
        $class = get_class($entity);

        // Cache the result for better performance
        if (!isset($this->entityConfigs[$class])) {
            $reflection = new \ReflectionClass($class);
            $attribute = $reflection->getAttributes(Staccacheable::class)[0] ?? null;

            $this->entityConfigs[$class] = $attribute ? $attribute->newInstance() : null;
        }

        return $this->entityConfigs[$class] !== null;
    }

    /**
     * Get cacheable configuration for an entity
     */
    public function getCacheableConfig(object $entity): ?Staccacheable
    {
        if ($this->isCacheable($entity)) {
            return $this->entityConfigs[get_class($entity)];
        }

        return null;
    }

    /**
     * Create cache key for entity
     */
    public function createCacheKey(string $entityClass, string $id): string
    {
        return sprintf('%s:%s:%s', $this->cachePrefix, $entityClass, $id);
    }

    /**
     * Cache an entity
     */
    public function cacheEntity(object $entity): void
    {
        if (!$this->isCacheable($entity)) {
            return;
        }

        try {
            $entityClass = get_class($entity);
            $metadata = $this->doctrine->getManager()->getClassMetadata($entityClass);
            $id = $this->getEntityId($entity, $metadata);

            if (!$id) {
                return; // Cannot cache without ID
            }

            $config = $this->entityConfigs[$entityClass];

            // Use a unique context key to prevent infinite recursion
            $context = [
                'staccache_serialization' => true,
                'circular_reference_handler' => function ($object, $format, $context) {
                    // Check if we've already seen this object in this serialization
                    if (!isset($context['seen_objects'])) {
                        $context['seen_objects'] = new \SplObjectStorage();
                    }

                    if ($context['seen_objects']->contains($object)) {
                        // Handle circular references by returning an array with id and class
                        if (method_exists($object, 'getId') && $object->getId()) {
                            return [
                                'id' => $object->getId(),
                                '__entity_class' => get_class($object),
                                '__is_circular_ref' => true,
                            ];
                        }
                        return null;
                    }

                    $context['seen_objects']->attach($object);
                    return $object;
                },
                'seen_objects' => new \SplObjectStorage(), // Track objects we've seen
                AbstractNormalizer::IGNORED_ATTRIBUTES => ['__initializer__', '__cloner__', '__isInitialized__'],
            ];

            $serialized = $this->serializer->serialize($entity, 'json', $context);

            // Add a hash for integrity verification
            $hash = $this->calculateEntityHash($serialized);

            $cacheData = json_encode([
                'data' => $serialized,
                'hash' => $hash,
                'class' => $entityClass,
                'timestamp' => time(),
            ], JSON_THROW_ON_ERROR);

            $cacheKey = $this->createCacheKey($entityClass, $id);
            $ttl = $config->ttl >= 0 ? $config->ttl : $this->defaultTtl;

            $this->redis->set($cacheKey, $cacheData);
            $this->redis->expire($cacheKey, $ttl);
        } catch (\Throwable $e) {
            // Log error but don't fail the application
            $this->logger->error('Error caching entity: ' . $e->getMessage());
        }
    }

    /**
     * Get entity from cache
     */
    public function getFromCache(string $entityClass, string $id, ?EntityManagerInterface $entityManager = null): ?object
    {
        // Add a static tracking array to prevent infinite recursion
        static $loadingEntities = [];
        $entityKey = $entityClass . ':' . $id;

        // If we're already loading this entity, return null to break the cycle
        if (isset($loadingEntities[$entityKey])) {
            return null;
        }

        // Mark this entity as being loaded
        $loadingEntities[$entityKey] = true;

        try {
            // Use createCacheKey instead of getCacheKey
            $cacheKey = $this->createCacheKey($entityClass, $id);

            // Check if entity exists in cache
            $cachedData = $this->redis->get($cacheKey);
            if (!$cachedData) {
                unset($loadingEntities[$entityKey]);
                return null;
            }

            // Decode the cached wrapper
            $cachedWrapper = json_decode($cachedData, true);
            if (!isset($cachedWrapper['data']) || !isset($cachedWrapper['hash']) || !isset($cachedWrapper['class'])) {
                // Invalid format, invalidate the cache
                $this->redis->del($cacheKey);
                unset($loadingEntities[$entityKey]);
                return null;
            }

            // Verify the expected class
            if ($cachedWrapper['class'] !== $entityClass) {
                // Class mismatch, invalidate the cache
                $this->redis->del($cacheKey);
                unset($loadingEntities[$entityKey]);
                return null;
            }

            // Verify integrity
            $serializedData = $cachedWrapper['data'];
            if (!$this->verifyIntegrityHash($serializedData, $cachedWrapper['hash'])) {
                // Integrity check failed, invalidate the cache
                $this->redis->del($cacheKey);
                unset($loadingEntities[$entityKey]);
                return null;
            }

            // Create entity and pre-set ID if possible
            $entity = new $entityClass();

            // Get entity manager - use provided one or get from doctrine
            $em = $entityManager ?? $this->doctrine->getManager();
            $metadata = $em->getClassMetadata($entityClass);
            $idField = $metadata->getSingleIdentifierFieldName();

            // Try to set ID directly using reflection if method not available
            if (!method_exists($entity, 'setId') && !method_exists($entity, 'set' . ucfirst($idField))) {
                try {
                    $reflectionProperty = $metadata->getReflectionProperty($idField);
                    $reflectionProperty->setAccessible(true);
                    $reflectionProperty->setValue($entity, $id);
                } catch (\Throwable $e) {
                    // Silent fail, will try with deserializer
                }
            }

            // Deserialize using Symfony's serializer
            $context = [
                AbstractNormalizer::OBJECT_TO_POPULATE => $entity,
                'doctrine' => $this->doctrine, // Pass doctrine to handle entity references
                'allow_extra_attributes' => true,
                'disable_type_enforcement' => true
            ];

            $entity = $this->serializer->deserialize($serializedData, $entityClass, 'json', $context);

            // Verify entity has ID after deserialization
            $idValue = $metadata->getIdentifierValues($entity);
            if (empty($idValue)) {
                // If ID is still missing, set it manually
                try {
                    $reflectionProperty = $metadata->getReflectionProperty($idField);
                    $reflectionProperty->setAccessible(true);
                    $reflectionProperty->setValue($entity, $id);
                } catch (\Throwable $e) {
                    // Log error but proceed
                    $this->logger->error('Failed to set entity ID after deserialization: ' . $e->getMessage());
                }
            }

            // Register the entity with Doctrine's UnitOfWork to ensure change tracking
            try {
                $uow = $em->getUnitOfWork();

                // Only register if not already in identity map to avoid duplicate registration
                if (!$uow->isInIdentityMap($entity)) {
                    // Create a snapshot of the entity's current state for proper change tracking
                    $originalData = [];
                    foreach ($metadata->getFieldNames() as $fieldName) {
                        if (!$metadata->isIdentifier($fieldName)) {
                            $originalData[$fieldName] = $metadata->getFieldValue($entity, $fieldName);
                        }
                    }

                    // Register the entity as managed with its current state as original state
                    $entityId = $metadata->getIdentifierValues($entity);
                    $uow->registerManaged($entity, $entityId, $originalData);
                }
            } catch (\Throwable $e) {
                // Log but continue - the controller can still force the changeset if needed
                $this->logger->error('Error registering cached entity with EntityManager: ' . $e->getMessage());
            }

            // Remove from loading tracking before returning
            unset($loadingEntities[$entityKey]);
            return $entity;
        } catch (\Throwable $e) {
            // Clean up tracking on error
            unset($loadingEntities[$entityKey]);
            // Log error but don't break the application
            $this->logger->error('Staccache deserialization error: ' . $e->getMessage());
            return null;
        }
    }
    /**
     * Ensure entity is properly tracked by Doctrine for change detection
     *
     * @param object $entity The entity to ensure is tracked
     * @param EntityManagerInterface|null $entityManager The entity manager (optional)
     * @return object The tracked entity
     */
    public function ensureEntityTracking(object $entity, $entityManager = null): object
    {
        try {
            // Get appropriate entity manager
            $em = $entityManager ?: $this->doctrine->getManagerForClass(get_class($entity));
            if (!$em) {
                return $entity;
            }

            $uow = $em->getUnitOfWork();
            $metadata = $em->getClassMetadata(get_class($entity));

            // Skip if already in identity map
            if ($uow->isInIdentityMap($entity)) {
                return $entity;
            }

            // Get identifier values
            $identifiers = $metadata->getIdentifierValues($entity);

            // If no valid identifiers, try to set them from ID property
            if (empty($identifiers)) {
                // Try to get ID from getId method
                if (method_exists($entity, 'getId') && $entity->getId() !== null) {
                    $idField = $metadata->getSingleIdentifierFieldName();

                    // Use reflection to set ID
                    $reflectionProperty = $metadata->getReflectionProperty($idField);
                    $reflectionProperty->setAccessible(true);
                    $reflectionProperty->setValue($entity, $entity->getId());

                    // Update identifiers
                    $identifiers = $metadata->getIdentifierValues($entity);
                }
            }

            // Only proceed if we have valid identifiers
            if (!empty($identifiers)) {
                // Get current values for all fields for change tracking
                $currentData = [];
                foreach ($metadata->getFieldNames() as $fieldName) {
                    if (!$metadata->isIdentifier($fieldName)) {
                        $currentData[$fieldName] = $metadata->getFieldValue($entity, $fieldName);
                    }
                }

                // Register entity with UnitOfWork
                $uow->registerManaged($entity, $identifiers, $currentData);

                // Log success
                $this->logger->debug(sprintf(
                    'Successfully registered entity %s with ID %s in UnitOfWork',
                    get_class($entity),
                    json_encode($identifiers)
                ));
            } else {
                $this->logger->debug(sprintf(
                    'Cannot register entity %s with UnitOfWork - no valid identifiers',
                    get_class($entity)
                ));
            }

            return $entity;
        } catch (\Throwable $e) {
            $this->logger->error('Error in ensureEntityTracking: ' . $e->getMessage());
            return $entity;
        }
    }

    /**
     * Invalidate cache for entity
     */
    public function invalidateCache(object $entity): void
    {
        if (!$this->isCacheable($entity)) {
            return;
        }

        try {
            $entityClass = get_class($entity);
            $metadata = $this->doctrine->getManager()->getClassMetadata($entityClass);
            $id = $this->getEntityId($entity, $metadata);

            if (!$id) {
                return; // Cannot invalidate without ID
            }

            $cacheKey = $this->createCacheKey($entityClass, $id);
            $this->redis->del($cacheKey);
        } catch (\Throwable $e) {
            $this->logger->error('Error invalidating entity cache: ' . $e->getMessage());
        }
    }

    /**
     * Lock an entity for update
     */
    public function lockEntity(object $entity): ?LockInterface
    {
        if (!$this->isCacheable($entity)) {
            return null;
        }

        try {
            $entityClass = get_class($entity);
            $metadata = $this->doctrine->getManager()->getClassMetadata($entityClass);
            $id = $this->getEntityId($entity, $metadata);

            if (!$id) {
                return null; // Cannot lock without ID
            }

            $lockKey = sprintf('%s:lock:%s:%s', $this->cachePrefix, $entityClass, $id);
            $lock = $this->lockFactory->createLock($lockKey, $this->lockTtl);

            if ($lock->acquire()) {
                $this->locks[$entityClass . ':' . $id] = $lock;
                return $lock;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error locking entity: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Unlock a previously locked entity
     */
    public function unlockEntity(object $entity): void
    {
        try {
            $entityClass = get_class($entity);
            $metadata = $this->doctrine->getManager()->getClassMetadata($entityClass);
            $id = $this->getEntityId($entity, $metadata);

            if (!$id) {
                return;
            }

            $lockKey = $entityClass . ':' . $id;

            if (isset($this->locks[$lockKey])) {
                $this->locks[$lockKey]->release();
                unset($this->locks[$lockKey]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error unlocking entity: ' . $e->getMessage());
        }
    }

    /**
     * Get entity ID (composite or single)
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
     * Calculate integrity hash
     */
    private function calculateEntityHash(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secretKey);
    }

    /**
     * Verify the integrity of cached entity data using the hash
     *
     * @param string $data The serialized entity data
     * @param string $hash The hash to verify against
     * @return bool True if integrity is verified, false otherwise
     */
    private function verifyIntegrityHash(string $data, string $hash): bool
    {
        // Calculate hash using the same method used when caching
        $calculatedHash = $this->calculateEntityHash($data);

        // Compare the calculated hash with the stored hash
        return hash_equals($calculatedHash, $hash);
    }


    /**
     * Cache a collection of entities
     */
    public function cacheCollection(array $entities, string $entityClass, array $criteria = [], array $orderBy = null, int $limit = null, int $offset = null): void
    {
        if (empty($entities)) {
            return;
        }

        try {
            // Check if entity class is cacheable
            $reflection = new \ReflectionClass($entityClass);
            $attribute = $reflection->getAttributes(\Phillarmonic\StaccacheBundle\Attribute\Staccacheable::class)[0] ?? null;

            if (!$attribute) {
                return; // Not cacheable
            }

            $config = $attribute->newInstance();

            // Store entity IDs instead of full entities to save space
            $entityIds = [];
            foreach ($entities as $entity) {
                // Cache individual entities first
                $this->cacheEntity($entity);

                // Get the entity ID
                $metadata = $this->doctrine->getManager()->getClassMetadata(get_class($entity));
                $id = $this->getEntityId($entity, $metadata);

                if ($id) {
                    $entityIds[] = $id;
                }
            }

            // Create collection cache key
            $cacheKey = $this->createCollectionCacheKey($entityClass, $criteria, $orderBy, $limit, $offset);

            // Serialize the collection data
            $cacheData = json_encode([
                'ids' => $entityIds,
                'class' => $entityClass,
                'timestamp' => time(),
                'hash' => $this->calculateCollectionHash($entityIds, $entityClass),
            ], JSON_THROW_ON_ERROR);

            // Get TTL from entity config or use default
            $ttl = $config->ttl >= 0 ? $config->ttl : $this->defaultTtl;

            // Store in Redis
            $this->redis->set($cacheKey, $cacheData);
            $this->redis->expire($cacheKey, $ttl);
        } catch (\Throwable $e) {
            $this->logger->error('Error caching collection: ' . $e->getMessage());
        }
    }

    /**
     * Get collection from cache
     */
    public function getCollectionFromCache(string $entityClass, array $criteria = [], array $orderBy = null, int $limit = null, int $offset = null): ?array
    {
        try {
            // Create collection cache key
            $cacheKey = $this->createCollectionCacheKey($entityClass, $criteria, $orderBy, $limit, $offset);

            // Check if collection exists in cache
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
            if (!$this->verifyCollectionHash($entityIds, $entityClass, $cachedWrapper['hash'])) {
                // Integrity check failed, invalidate the cache
                $this->redis->del($cacheKey);
                return null;
            }

            // Get entity manager
            $em = $this->doctrine->getManagerForClass($entityClass);
            if (!$em) {
                return null;
            }

            // Load individual entities from cache
            $collection = [];
            foreach ($entityIds as $id) {
                $entity = $this->getFromCache($entityClass, (string)$id, $em);

                // If entity is not in cache, fetch it from database
                if ($entity === null) {
                    $entity = $em->getRepository($entityClass)->find($id);

                    // If still null, skip this entity
                    if ($entity === null) {
                        continue;
                    }

                    // Cache the entity
                    $this->cacheEntity($entity);
                }

                // Add to collection
                $collection[] = $entity;
            }

            // If limit and offset were applied, ensure we respect them
            if ($limit !== null) {
                $start = $offset ?? 0;
                $collection = array_slice($collection, $start, $limit);
            }

            return $collection;
        } catch (\Throwable $e) {
            $this->logger->error('Error getting collection from cache: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Invalidate all collection caches for an entity class
     */
    public function invalidateCollectionCaches(string $entityClass): void
    {
        try {
            // Debug log for pattern construction
            $basePattern = $this->cachePrefix . ':collection:' . $entityClass;
            $this->logger->debug("Base pattern for invalidation: " . $basePattern);

            // Try different pattern formats (some Redis clients handle wildcards differently)
            $patterns = [
                $basePattern . '*',            // Standard format
                $basePattern . ':*',           // With separator
                $this->cachePrefix . ':collection:*' // All collections (if entity specific fails)
            ];

            $totalDeleted = 0;
            foreach ($patterns as $pattern) {
                $this->logger->debug("Trying pattern: " . $pattern);

                // Get all matching keys
                $keys = $this->scanKeys($pattern);

                // Delete all matching keys
                if (!empty($keys)) {
                    $this->logger->debug("Found " . count($keys) . " keys to delete for pattern: " . $pattern);
                    $deleted = $this->redis->del(...$keys);
                    $this->logger->debug("Deleted $deleted keys");
                    $totalDeleted += $deleted;
                } else {
                    $this->logger->debug("No keys found for pattern: " . $pattern);
                }
            }

            $this->logger->debug("Total deleted collection cache keys: " . $totalDeleted);

            // Final verification - check if any keys are still present
            foreach ($patterns as $pattern) {
                $remainingKeys = $this->scanKeys($pattern);
                if (!empty($remainingKeys)) {
                    $this->logger->debug("WARNING: Still found " . count($remainingKeys) . " keys after deletion for pattern: " . $pattern);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error invalidating collection caches: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }


    /**
     * Create cache key for collection queries
     */
    public function createCollectionCacheKey(string $entityClass, array $criteria = [], array $orderBy = null, int $limit = null, int $offset = null): string
    {
        $key = $this->cachePrefix . ':collection:' . $entityClass;

        // Add criteria to key if present
        if (!empty($criteria)) {
            $key .= ':' . md5(serialize($criteria));
        } else {
            $key .= ':all'; // For findAll() queries
        }

        // Add orderBy to key if present
        if ($orderBy !== null) {
            $key .= ':order_' . md5(serialize($orderBy));
        }

        // Add limit and offset to key if present
        if ($limit !== null) {
            $key .= ':limit_' . $limit;
        }

        if ($offset !== null) {
            $key .= ':offset_' . $offset;
        }

        // Debug log the created key
        $this->logger->debug("Created collection cache key: " . $key);

        return $key;
    }


    /**
     * Scan Redis for keys matching a pattern with improved pattern handling
     */
    private function scanKeys(string $pattern): array
    {
        $keys = [];

        // Redis pattern syntax can be tricky - ensure it's properly formatted for wildcards
        // Make sure '*' is properly escaped in the pattern if needed
        $safePattern = $pattern;

        $this->logger->debug("Scanning for keys with pattern: " . $safePattern);

        try {
            // Try direct KEYS command first if the database is small
            // This is faster for small datasets and more reliable for pattern matching
            try {
                $directKeys = $this->redis->keys($safePattern);
                if (is_array($directKeys) && !empty($directKeys)) {
                    $this->logger->debug("Found " . count($directKeys) . " keys using direct KEYS command");
                    return $directKeys;
                }
            } catch (\Throwable $e) {
                $this->logger->error("Direct KEYS command failed: " . $e->getMessage() . " - falling back to SCAN");
            }

            // Fall back to SCAN for larger databases
            $iterator = null;
            $scanKeys = $this->redis->scan($iterator, $safePattern, 100);

            while ($scanKeys && !empty($scanKeys)) {
                array_push($keys, ...$scanKeys);

                if ($iterator === 0 || $iterator === false) {
                    break;
                }

                $scanKeys = $this->redis->scan($iterator, $safePattern, 100);
            }

           $this->logger->debug("Found " . count($keys) . " keys using SCAN");

            // Last resort: try a broader pattern if specific pattern fails
            if (empty($keys) && strpos($safePattern, '*') !== false) {
                // Try a more general pattern
                $broadPattern = substr($safePattern, 0, strrpos($safePattern, ':') + 1) . '*';
                $this->logger->debug("No keys found with specific pattern. Trying broader pattern: " . $broadPattern);

                $broadKeys = $this->redis->keys($broadPattern);
                if (is_array($broadKeys) && !empty($broadKeys)) {
                    $this->logger->debug("Found " . count($broadKeys) . " keys with broader pattern");

                    // Filter keys to match original pattern more closely
                    $basePrefix = str_replace('*', '', $safePattern);
                    $keys = array_filter($broadKeys, function($key) use ($basePrefix) {
                        return strpos($key, $basePrefix) === 0;
                    });

                    $this->logger->debug("Filtered to " . count($keys) . " keys matching original intent");
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error in scanKeys: ' . $e->getMessage());
        }

        return is_array($keys) ? $keys : [];
    }

    /**
     * Calculate hash for collection integrity check
     */
    private function calculateCollectionHash(array $entityIds, string $entityClass): string
    {
        $data = $entityClass . ':' . implode(',', $entityIds);
        return hash_hmac('sha256', $data, $this->secretKey);
    }

    /**
     * Verify collection hash for integrity check
     */
    private function verifyCollectionHash(array $entityIds, string $entityClass, string $hash): bool
    {
        $calculatedHash = $this->calculateCollectionHash($entityIds, $entityClass);
        return hash_equals($calculatedHash, $hash);
    }

    /**
     * Invalidate cache by its key directly
     * This is useful for cases where we have the cache key but not the entity object
     */
    public function invalidateCacheByKey(string $cacheKey): void
    {
        try {
            $this->redis->del($cacheKey);
        } catch (\Throwable $e) {
            $this->logger->error('Error invalidating entity cache by key: ' . $e->getMessage());
        }
    }
}