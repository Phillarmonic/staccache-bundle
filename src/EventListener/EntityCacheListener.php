<?php

namespace Phillarmonic\StaccacheBundle\EventListener;

use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Phillarmonic\StaccacheBundle\Cache\EntityCacheManager;
use Phillarmonic\StaccacheBundle\Cache\QueryCacheManager;
use Psr\Log\LoggerInterface;

/**
 * Doctrine event listener to handle entity caching based on lifecycle events
 */
class EntityCacheListener
{
    private EntityCacheManager $cacheManager;
    private ?QueryCacheManager $queryCacheManager;
    private bool $autoCacheOnLoad;
    private array $entitiesToLock = [];
    private array $entitiesToUpdate = [];
    private array $entitiesToInvalidate = [];
    private array $collectionCachesToInvalidate = [];
    private array $queryCachesToInvalidate = [];

    public function __construct(
        EntityCacheManager $cacheManager,
        bool $autoCacheOnLoad,
        ?QueryCacheManager $queryCacheManager = null,
        private readonly ?LoggerInterface $logger = null
    )
    {
        $this->cacheManager = $cacheManager;
        $this->queryCacheManager = $queryCacheManager;
        $this->autoCacheOnLoad = $autoCacheOnLoad;
    }

    /**
     * Cache entity after it's loaded from the database
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        if (!$this->autoCacheOnLoad) {
            return;
        }

        $entity = $args->getObject();
        if ($this->cacheManager->isCacheable($entity)) {
            $this->cacheManager->cacheEntity($entity);
        }
    }

    /**
     * Before entity update, acquire lock if configured to do so
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->cacheManager->isCacheable($entity)) {
            $config = $this->cacheManager->getCacheableConfig($entity);

            if ($config && $config->lockOnWrite) {
                $this->entitiesToLock[] = $entity;
                $this->cacheManager->lockEntity($entity);
            }

            // Mark for update after flush
            $this->entitiesToUpdate[] = $entity;

            // Mark entity class for collection cache invalidation
            $entityClass = get_class($entity);
            if (!in_array($entityClass, $this->collectionCachesToInvalidate)) {
                $this->collectionCachesToInvalidate[] = $entityClass;
            }

            // Also mark for query cache invalidation
            if ($this->queryCacheManager && !in_array($entityClass, $this->queryCachesToInvalidate)) {
                $this->queryCachesToInvalidate[] = $entityClass;

                // Immediately invalidate query caches to ensure consistency
                $this->queryCacheManager->invalidateEntityQueryCaches($entityClass);
                $this->logger?->debug("Immediately invalidated query caches for entity class: {$entityClass} in preUpdate");
            }
        }
    }

     /**
     * Before entity removal, invalidate cache and acquire lock if configured
     */
    public function preRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->cacheManager->isCacheable($entity)) {
            $config = $this->cacheManager->getCacheableConfig($entity);

            if ($config && $config->lockOnWrite) {
                $this->entitiesToLock[] = $entity;
                $this->cacheManager->lockEntity($entity);
            }

            // Store entity information before it's removed so we can invalidate it later
            // This is critical because after removal, we may lose ID information
            $entityClass = get_class($entity);
            $entityId = $this->getEntityId($entity, $args->getObjectManager()->getClassMetadata($entityClass));

            if ($entityId) {
                // Store both class and ID for later invalidation
                $this->entitiesToInvalidate[] = [
                    'entity' => $entity,
                    'class' => $entityClass,
                    'id' => $entityId
                ];
            } else {
                // If we can't get ID, still try with the entity itself
                $this->entitiesToInvalidate[] = [
                    'entity' => $entity,
                    'class' => $entityClass,
                    'id' => null
                ];
            }

            // Mark entity class for collection cache invalidation
            if (!in_array($entityClass, $this->collectionCachesToInvalidate)) {
                $this->collectionCachesToInvalidate[] = $entityClass;
            }

            // Also mark for query cache invalidation and immediately invalidate
            if ($this->queryCacheManager && !in_array($entityClass, $this->queryCachesToInvalidate)) {
                $this->queryCachesToInvalidate[] = $entityClass;

                // Immediately invalidate query caches to ensure consistency
                $this->queryCacheManager->invalidateEntityQueryCaches($entityClass);
                $this->logger?->debug("Immediately invalidated query caches for entity class: {$entityClass} in preRemove");
            }

            // IMPORTANT: Directly invalidate the entity cache immediately
            // This ensures that it's removed from cache even if postRemove doesn't run correctly
            $this->cacheManager->invalidateCache($entity);
        }
    }

    /**
     * After entity is persisted, cache it and invalidate collection caches
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->cacheManager->isCacheable($entity)) {
            // Immediately cache the entity after persistence
            $this->cacheManager->cacheEntity($entity);

            // Also mark for potential update after flush to ensure we have the most current state
            $this->entitiesToUpdate[] = $entity;

            // Mark entity class for collection cache invalidation
            $entityClass = get_class($entity);
            if (!in_array($entityClass, $this->collectionCachesToInvalidate)) {
                $this->collectionCachesToInvalidate[] = $entityClass;
            }

            // Also mark for query cache invalidation
            if ($this->queryCacheManager && !in_array($entityClass, $this->queryCachesToInvalidate)) {
                $this->queryCachesToInvalidate[] = $entityClass;
            }

            // IMPORTANT: Directly invalidate collection caches here for immediate effect
            // This ensures that any findAll or findBy queries will be refreshed
            $this->cacheManager->invalidateCollectionCaches($entityClass);

            // Also invalidate query caches immediately
            if ($this->queryCacheManager) {
                $this->queryCacheManager->invalidateEntityQueryCaches($entityClass);
                $this->logger?->debug("Immediately invalidated query caches for entity class: {$entityClass} in postPersist");
            }
        }
    }

    /**
     * After entity is updated, refresh cache and invalidate collection caches
     */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->cacheManager->isCacheable($entity)) {
            // Immediately cache the updated entity
            $this->cacheManager->cacheEntity($entity);

            // Mark entity class for collection cache invalidation
            $entityClass = get_class($entity);
            if (!in_array($entityClass, $this->collectionCachesToInvalidate)) {
                $this->collectionCachesToInvalidate[] = $entityClass;
            }

            // Also mark for query cache invalidation
            if ($this->queryCacheManager && !in_array($entityClass, $this->queryCachesToInvalidate)) {
                $this->queryCachesToInvalidate[] = $entityClass;
            }

            // IMPORTANT: Directly invalidate collection caches here for immediate effect
            // This ensures that any findAll or findBy queries will be refreshed
            $this->cacheManager->invalidateCollectionCaches($entityClass);

            // Also invalidate query caches immediately
            if ($this->queryCacheManager) {
                $this->queryCacheManager->invalidateEntityQueryCaches($entityClass);
                $this->logger?->debug("Immediately invalidated query caches for entity class: {$entityClass} in postUpdate");
            }
        }
    }

    /**
     * After entity is removed, invalidate cache and collection caches
     */
    public function postRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->cacheManager->isCacheable($entity)) {
            // Immediately invalidate the cache again to be sure
            $this->cacheManager->invalidateCache($entity);

            // Mark entity class for collection cache invalidation
            $entityClass = get_class($entity);
            if (!in_array($entityClass, $this->collectionCachesToInvalidate)) {
                $this->collectionCachesToInvalidate[] = $entityClass;
            }

            // Also mark for query cache invalidation
            if ($this->queryCacheManager && !in_array($entityClass, $this->queryCachesToInvalidate)) {
                $this->queryCachesToInvalidate[] = $entityClass;
            }

            // IMPORTANT: Directly invalidate collection caches here for immediate effect
            $this->cacheManager->invalidateCollectionCaches($entityClass);

            // Also invalidate query caches immediately
            if ($this->queryCacheManager) {
                $this->queryCacheManager->invalidateEntityQueryCaches($entityClass);
                $this->logger?->debug("Immediately invalidated query caches for entity class: {$entityClass} in postRemove");
            }
        }
    }


    /**
     * After flush completes, process all cached updates and invalidations
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        // Process updates first
        foreach ($this->entitiesToUpdate as $entity) {
            // Refresh the cache one more time to ensure consistency
            $this->cacheManager->cacheEntity($entity);
        }

        // Process invalidations with improved handling
        foreach ($this->entitiesToInvalidate as $entityInfo) {
            if (isset($entityInfo['entity'])) {
                // First try to invalidate using the entity object
                $this->cacheManager->invalidateCache($entityInfo['entity']);
            }

            // As a fallback, if we have class and ID information, use that
            if (isset($entityInfo['class']) && isset($entityInfo['id']) && $entityInfo['id'] !== null) {
                $cacheKey = $this->cacheManager->createCacheKey($entityInfo['class'], $entityInfo['id']);
                $this->cacheManager->invalidateCacheByKey($cacheKey);
            }
        }

        // Double-check invalidation of collection caches for affected entity classes
        foreach ($this->collectionCachesToInvalidate as $entityClass) {
            $this->cacheManager->invalidateCollectionCaches($entityClass);
            $this->logger?->debug("Post-flush invalidation of collection caches for: {$entityClass}");
        }

        // Double-check invalidation of query caches for affected entity classes
        if ($this->queryCacheManager) {
            foreach ($this->queryCachesToInvalidate as $entityClass) {
                $this->queryCacheManager->invalidateEntityQueryCaches($entityClass);
                $this->logger?->debug("Post-flush invalidation of query caches for: {$entityClass}");
            }
        }

        // Finally, release any locks
        foreach ($this->entitiesToLock as $entity) {
            $this->cacheManager->unlockEntity($entity);
        }

        // Clear the tracking arrays
        $this->entitiesToUpdate = [];
        $this->entitiesToInvalidate = [];
        $this->entitiesToLock = [];
        $this->collectionCachesToInvalidate = [];
        $this->queryCachesToInvalidate = [];
    }

    /**
     * Get entity ID (composite or single)
     * (Adding this helper method to handle ID extraction)
     */
    private function getEntityId(object $entity, $metadata): ?string
    {
        try {
            $ids = $metadata->getIdentifierValues($entity);

            if (empty($ids)) {
                return null;
            }

            // For composite keys, create a hash
            if (count($ids) > 1) {
                return md5(serialize($ids));
            }

            return (string) reset($ids);
        } catch (\Throwable $e) {
            // If we can't get the ID, log the error and return null
            $this->logger?->error('Error getting entity ID for cache invalidation: ' . $e->getMessage());
            return null;
        }
    }
}