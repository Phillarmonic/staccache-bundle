<?php

namespace Phillarmonic\StaccacheBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectRepository;
use Phillarmonic\StaccacheBundle\Cache\EntityCacheManager;
use Psr\Log\LoggerInterface;

/**
 * Repository decorator that adds caching capabilities to entity repositories
 */
class CachedEntityRepository implements ObjectRepository
{
    private ObjectRepository $innerRepository;
    private EntityCacheManager $cacheManager;
    private EntityManagerInterface $entityManager;
    private ClassMetadata $classMetadata;

    public function __construct(
        ObjectRepository $innerRepository,
        EntityCacheManager $cacheManager,
        EntityManagerInterface $entityManager,
        ClassMetadata $classMetadata,
        private readonly LoggerInterface $logger
    ) {
        $this->innerRepository = $innerRepository;
        $this->cacheManager = $cacheManager;
        $this->entityManager = $entityManager;
        $this->classMetadata = $classMetadata;
    }


    /**
     * {@inheritdoc}
     */
    public function find($id, $lockMode = null, $lockVersion = null)
    {
        // Check cache first if not using locks
        if ($lockMode === null) {
            $className = $this->classMetadata->getName();
            $cachedEntity = $this->cacheManager->getFromCache($className, (string) $id, $this->entityManager);

            if ($cachedEntity !== null) {
                // Ensure the entity is properly tracked by Doctrine for change detection
                // This creates a fresh snapshot for change detection
                try {
                    $em = $this->entityManager;
                    $uow = $em->getUnitOfWork();

                    // Only update tracking if not already managed
                    if (!$uow->isInIdentityMap($cachedEntity)) {
                        // Option 1: Merge the entity - this is safer but copies data
                        // $cachedEntity = $em->merge($cachedEntity);

                        // Option 2: Register entity with UnitOfWork directly (more efficient)
                        $metadata = $em->getClassMetadata(get_class($cachedEntity));

                        // Get current values for change tracking
                        $currentData = [];
                        foreach ($metadata->getFieldNames() as $fieldName) {
                            if (!$metadata->isIdentifier($fieldName)) {
                                $currentData[$fieldName] = $metadata->getFieldValue($cachedEntity, $fieldName);
                            }
                        }

                        // Register with UnitOfWork and establish baseline for detecting changes
                        $identifiers = $metadata->getIdentifierValues($cachedEntity);
                        $uow->registerManaged($cachedEntity, $identifiers, $currentData);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Error ensuring cached entity change tracking: ' . $e->getMessage());
                    // If registration fails, fall back to database
                    return $this->innerRepository->find($id, $lockMode, $lockVersion);
                }

                return $cachedEntity;
            }
        }

        // Fall back to database if not in cache or using locks
        $entity = $this->innerRepository->find($id, $lockMode, $lockVersion);

        // Cache the entity if found and not using locks
        if ($entity !== null && $lockMode === null) {
            $this->cacheManager->cacheEntity($entity);
        }

        return $entity;
    }

    /**
     * {@inheritdoc}
     */
    public function findAll()
    {
        // For collections, we don't use cache as they could be large
        return $this->innerRepository->findAll();
    }

    /**
     * {@inheritdoc}
     */
    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
    {
        // For complex queries, we don't use cache
        return $this->innerRepository->findBy($criteria, $orderBy, $limit, $offset);
    }

    /**
     * {@inheritdoc}
     */
    public function findOneBy(array $criteria, ?array $orderBy = null)
    {
        // For now, we only cache by primary key
        // Complex criteria queries go directly to the database
        return $this->innerRepository->findOneBy($criteria, $orderBy);
    }

    /**
     * {@inheritdoc}
     */
    public function getClassName()
    {
        return $this->innerRepository->getClassName();
    }

    /**
     * Proxy all other method calls to the inner repository
     */
    public function __call($method, $args)
    {
        if (method_exists($this->innerRepository, $method)) {
            return call_user_func_array([$this->innerRepository, $method], $args);
        }

        throw new \BadMethodCallException(
            sprintf("Method '%s' not found in repository class.", $method)
        );
    }
}