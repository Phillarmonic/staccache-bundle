<?php

namespace Phillarmonic\StaccacheBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Phillarmonic\StaccacheBundle\Cache\EntityCacheManager;
use Phillarmonic\StaccacheBundle\Cache\QueryCacheManager;

/**
 * Base repository class with caching capabilities
 *
 * @template T of object
 * @extends ServiceEntityRepository<T>
 */
abstract class CachedServiceEntityRepository extends ServiceEntityRepository
{
    protected EntityCacheManager $cacheManager;
    protected QueryCacheManager $queryCacheManager;
    protected bool $bypassCache = false;
    protected bool $bypassCollectionCache = false;
    protected bool $bypassQueryCache = false;
    protected int $defaultQueryTtl;

    /**
     * @param ManagerRegistry $registry
     * @param class-string<T> $entityClass
     * @param EntityCacheManager $cacheManager
     * @param QueryCacheManager $queryCacheManager
     * @param int $defaultQueryTtl
     */
    public function __construct(
        ManagerRegistry $registry,
        string $entityClass,
        EntityCacheManager $cacheManager,
        QueryCacheManager $queryCacheManager,
        int $defaultQueryTtl = 3600 // Default 1 hour TTL for queries
    ) {
        parent::__construct($registry, $entityClass);
        $this->cacheManager = $cacheManager;
        $this->queryCacheManager = $queryCacheManager;
        $this->defaultQueryTtl = $defaultQueryTtl;
    }

    /**
     * {@inheritdoc}
     */
    public function find($id, $lockMode = null, $lockVersion = null): object|null
    {
        // Check if we should bypass cache
        if ($this->bypassCache || $lockMode !== null) {
            $result = parent::find($id, $lockMode, $lockVersion);
            $this->resetBypassCache();
            return $result;
        }

        // Try to get from cache first
        $className = $this->getEntityName();
        $cachedEntity = $this->cacheManager->getFromCache($className, (string) $id, $this->getEntityManager());

        if ($cachedEntity !== null) {
            return $cachedEntity;
        }

        // Get from database if not in cache
        $entity = parent::find($id, $lockMode, $lockVersion);

        // Cache the entity if found and entity is cacheable
        if ($entity !== null && $this->cacheManager->isCacheable($entity)) {
            $this->cacheManager->cacheEntity($entity);
        }

        $this->resetBypassCache();
        return $entity;
    }

    /**
     * {@inheritdoc}
     */
    public function findAll(): array
    {
        // Check if we should bypass cache
        if ($this->bypassCache || $this->bypassCollectionCache) {
            $result = parent::findAll();
            $this->resetBypassCache();
            return $result;
        }

        // Try to get from collection cache
        $className = $this->getEntityName();
        $cachedCollection = $this->cacheManager->getCollectionFromCache($className);

        if ($cachedCollection !== null) {
            return $cachedCollection;
        }

        // Get from database if not in cache
        $collection = parent::findAll();

        // Cache the collection if not empty
        if (!empty($collection)) {
            $this->cacheManager->cacheCollection($collection, $className);
        }

        $this->resetBypassCache();
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array
    {
        // Check if we should bypass cache
        if ($this->bypassCache || $this->bypassCollectionCache) {
            $result = parent::findBy($criteria, $orderBy, $limit, $offset);
            $this->resetBypassCache();
            return $result;
        }

        // Try to get from collection cache
        $className = $this->getEntityName();
        $cachedCollection = $this->cacheManager->getCollectionFromCache(
            $className,
            $criteria,
            $orderBy,
            $limit,
            $offset
        );

        if ($cachedCollection !== null) {
            return $cachedCollection;
        }

        // Get from database if not in cache
        $collection = parent::findBy($criteria, $orderBy, $limit, $offset);

        // Cache the collection if not empty
        if (!empty($collection)) {
            $this->cacheManager->cacheCollection(
                $collection,
                $className,
                $criteria,
                $orderBy,
                $limit,
                $offset
            );
        }

        $this->resetBypassCache();
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): object|null
    {
        // Only use cache for single ID lookup
        if (!$this->bypassCache && count($criteria) === 1 && isset($criteria['id'])) {
            return $this->find($criteria['id']);
        }

        $result = parent::findOneBy($criteria, $orderBy);

        // Reset bypass cache flag
        $this->resetBypassCache();

        return $result;
    }

    /**
     * Execute query with caching
     *
     * @param QueryBuilder|Query $query The query to execute
     * @param string $cacheKey Unique identifier for the query
     * @param int|null $ttl Cache TTL in seconds (null to use default)
     * @return array The query result
     */
    public function executeQueryWithCache($query, string $cacheKey, ?int $ttl = null): array
    {
        // Check if we should bypass cache
        if ($this->bypassCache || $this->bypassQueryCache) {
            $result = $query instanceof QueryBuilder ? $query->getQuery()->getResult() : $query->getResult();
            $this->resetBypassCache();
            return $result;
        }

        $className = $this->getEntityName();

        // Try to get from query cache
        $cachedResult = $this->queryCacheManager->getCachedQueryResult($cacheKey, $className);

        if ($cachedResult !== null) {
            return $cachedResult;
        }

        // Execute query if not in cache
        $result = $query instanceof QueryBuilder ? $query->getQuery()->getResult() : $query->getResult();

        // Cache the result if not empty
        if (!empty($result)) {
            $this->queryCacheManager->cacheQueryResult($cacheKey, $result, $className, $ttl ?? $this->defaultQueryTtl);
        }

        $this->resetBypassCache();
        return $result;
    }

    /**
     * Use this to temporarily bypass cache for the next operation
     */
    public function withoutCache(): self
    {
        $this->bypassCache = true;
        return $this;
    }

    /**
     * Use this to temporarily bypass collection cache for the next operation
     */
    public function withoutCollectionCache(): self
    {
        $this->bypassCollectionCache = true;
        return $this;
    }

    /**
     * Use this to temporarily bypass query cache for the next operation
     */
    public function withoutQueryCache(): self
    {
        $this->bypassQueryCache = true;
        return $this;
    }

    /**
     * Reset the bypass cache flags after operation
     */
    protected function resetBypassCache(): void
    {
        $this->bypassCache = false;
        $this->bypassCollectionCache = false;
        $this->bypassQueryCache = false;
    }

    /**
     * Manually cache an entity
     *
     * @param object $entity
     * @return void
     */
    public function cacheEntity(object $entity): void
    {
        if ($this->cacheManager->isCacheable($entity)) {
            $this->cacheManager->cacheEntity($entity);
        }
    }

    /**
     * Manually invalidate an entity's cache
     *
     * @param object $entity
     * @return void
     */
    public function invalidateCache(object $entity): void
    {
        if ($this->cacheManager->isCacheable($entity)) {
            $this->cacheManager->invalidateCache($entity);
        }
    }

    /**
     * Manually invalidate all collection caches for this entity type
     *
     * @return void
     */
    public function invalidateCollectionCaches(): void
    {
        $className = $this->getEntityName();
        $this->cacheManager->invalidateCollectionCaches($className);
    }

    /**
     * Manually invalidate a specific query cache
     *
     * @param string $cacheKey The query cache key
     * @return void
     */
    public function invalidateQueryCache(string $cacheKey): void
    {
        $className = $this->getEntityName();
        $this->queryCacheManager->invalidateQueryCache($cacheKey, $className);
    }

    /**
     * Manually invalidate all query caches for this entity type
     *
     * @return void
     */
    public function invalidateAllQueryCaches(): void
    {
        $className = $this->getEntityName();
        $this->queryCacheManager->invalidateEntityQueryCaches($className);
    }
}