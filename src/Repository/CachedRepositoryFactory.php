<?php

namespace Phillarmonic\StaccacheBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\Persistence\ObjectRepository;
use Phillarmonic\StaccacheBundle\Cache\EntityCacheManager;

/**
 * Factory for creating repositories with caching capabilities
 */
class CachedRepositoryFactory implements RepositoryFactory
{
    private EntityCacheManager $cacheManager;
    private array $repositories = [];

    public function __construct(EntityCacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    /**
     * {@inheritdoc}
     */
    public function getRepository(EntityManagerInterface $entityManager, $entityName): ObjectRepository
    {
        $repositoryHash = $entityManager->getClassMetadata($entityName)->getName() . spl_object_hash($entityManager);

        if (isset($this->repositories[$repositoryHash])) {
            return $this->repositories[$repositoryHash];
        }

        // Check if this entity class has the Staccacheable attribute
        $reflection = new \ReflectionClass($entityName);
        $isCacheable = !empty($reflection->getAttributes(\Phillarmonic\StaccacheBundle\Attribute\Staccacheable::class));

        $repository = $entityManager->getConfiguration()->getRepositoryFactory()->getRepository($entityManager, $entityName);

        // If entity is cacheable, wrap the repository with a caching layer
        if ($isCacheable) {
            $repository = new CachedEntityRepository(
                $repository,
                $this->cacheManager,
                $entityManager,
                $entityManager->getClassMetadata($entityName)
            );
        }

        $this->repositories[$repositoryHash] = $repository;

        return $repository;
    }
}