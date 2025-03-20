<?php

namespace Phillarmonic\StaccacheBundle\Request;

use Doctrine\Persistence\ManagerRegistry;
use Phillarmonic\StaccacheBundle\Cache\EntityCacheManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use ReflectionClass;

/**
 * Value resolver that uses entity cache when possible
 */
class CachedEntityValueResolver implements ValueResolverInterface
{
    private ManagerRegistry $doctrine;
    private EntityCacheManager $cacheManager;

    public function __construct(
        ManagerRegistry $doctrine,
        EntityCacheManager $cacheManager,
        private readonly ?LoggerInterface $logger
    )
    {
        $this->doctrine = $doctrine;
        $this->cacheManager = $cacheManager;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        // Check if this argument is an entity type
        $type = $argument->getType();
        if (!$type || !class_exists($type) || !$this->isEntity($type)) {
            return [];
        }

        // Check if entity is cacheable
        if (!$this->isEntityCacheable($type)) {
            return [];
        }

        // Get identifier from request
        $id = $this->getIdentifier($request, $type, $argument->getName());
        if ($id === null) {
            return [];
        }

        // Get the entity manager for this entity type
        $entityManager = $this->doctrine->getManagerForClass($type);
        if (!$entityManager) {
            $this->logger->debug("No entity manager found for class $type");
            return [];
        }

        // First try database lookup for reliability
        $entity = null;
        try {
            $repository = $this->doctrine->getRepository($type);
            $entity = $repository->find($id);

            // If entity found in database, update cache and return it
            if ($entity !== null) {
                $this->cacheManager->cacheEntity($entity);
                return [$entity];
            }
        } catch (\Throwable $e) {
            $this->logger->error("Error loading entity from database: " . $e->getMessage());
            // Continue to try cache as fallback
        }

        // Try to load from cache as fallback
        try {
            $entity = $this->cacheManager->getFromCache($type, (string) $id, $entityManager);

            // If found in cache, ensure it's properly tracked
            if ($entity !== null) {
                // Validate entity has proper ID
                $metadata = $entityManager->getClassMetadata($type);
                $idValues = $metadata->getIdentifierValues($entity);

                if (!empty($idValues)) {
                    return [$entity];
                } else {
                    $this->logger->debug("Entity from cache has no valid ID");
                    $entity = null; // Reset to trigger not found exception if needed
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error("Error loading entity from cache: " . $e->getMessage());
            $entity = null;
        }

        // Handle case when entity is not optional but not found
        if ($entity === null && !$argument->isNullable()) {
            // Last chance - try clearing entity manager and reloading
            try {
                // Get fresh entity manager and clear any cached state
                $entityManager->clear($type);

                $repository = $this->doctrine->getRepository($type);
                $entity = $repository->find($id);

                if ($entity !== null) {
                    $this->cacheManager->cacheEntity($entity);
                    return [$entity];
                }
            } catch (\Throwable $e) {
                $this->logger->error("Final attempt to load entity failed: " . $e->getMessage());
            }

            throw new NotFoundHttpException(sprintf(
                'Entity "%s" with id "%s" not found (checked both database and cache).',
                $type,
                $id
            ));
        }

        return [$entity];
    }

    /**
     * Check if the type is an entity
     */
    private function isEntity(string $class): bool
    {
        try {
            return $this->doctrine->getRepository($class) instanceof \Doctrine\Persistence\ObjectRepository;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if entity class is cacheable
     */
    private function isEntityCacheable(string $class): bool
    {
        try {
            $reflection = new ReflectionClass($class);
            return !empty($reflection->getAttributes('Phillarmonic\\StaccacheBundle\\Attribute\\Staccacheable'));
        } catch (\ReflectionException $e) {
            return false;
        }
    }

    /**
     * Gets the entity identifier from request
     */
    private function getIdentifier(Request $request, string $class, string $argumentName): ?string
    {
        // Try to get ID from route parameter with the same name as the argument
        if ($request->attributes->has($argumentName) && !is_object($request->attributes->get($argumentName))) {
            return $request->attributes->get($argumentName);
        }

        // Try to get ID from standard 'id' parameter
        if ($request->attributes->has('id')) {
            return $request->attributes->get('id');
        }

        // Try with singularized class name + _id
        $shortName = strtolower((new \ReflectionClass($class))->getShortName());
        $idParam = $shortName . '_id';
        if ($request->attributes->has($idParam)) {
            return $request->attributes->get($idParam);
        }

        return null;
    }
}