<?php

namespace Phillarmonic\StaccacheBundle\Serializer;

use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Custom denormalizer for handling entity references during deserialization
 */
class EntityDenormalizer implements DenormalizerInterface
{
    private static array $processedEntities = [];

    public function __construct(
        private readonly ObjectNormalizer $normalizer,
        private readonly ManagerRegistry $doctrine,
        private readonly ?LoggerInterface $logger
    )
    {

    }

    /**
     * {@inheritdoc}
     */
    public function denormalize(mixed $data, string $type, string $format = null, array $context = []): mixed
    {
        // Generate a unique key for this denormalization operation
        $dataHash = md5(json_encode($data) . $type);

        // If we've already processed this entity in this request, return it directly
        if (isset(self::$processedEntities[$dataHash])) {
            return self::$processedEntities[$dataHash];
        }

        // Check if this is an entity reference
        if (is_array($data) && isset($data['id']) && isset($data['__entity_class'])) {
            $entityClass = $data['__entity_class'];
            $entityId = $data['id'];

            // If it's a circular reference, handle it specially
            if (isset($data['__is_circular_ref']) && $data['__is_circular_ref']) {
                // Try to find the entity in the database instead of denormalizing
                $entity = $this->doctrine->getRepository($entityClass)->find($entityId);
                if ($entity !== null) {
                    return $entity;
                }
            }

            // Try to find the entity in the database
            $entity = $this->doctrine->getRepository($entityClass)->find($entityId);

            // If entity not found in database, we'll create a stub with just the ID
            if ($entity === null && class_exists($entityClass)) {
                try {
                    // Create new instance
                    $entity = new $entityClass();

                    // Set the ID using reflection
                    $metadata = $this->doctrine->getManagerForClass($entityClass)->getClassMetadata($entityClass);
                    $idField = $metadata->getSingleIdentifierFieldName();

                    $reflectionProperty = $metadata->getReflectionProperty($idField);
                    $reflectionProperty->setAccessible(true);
                    $reflectionProperty->setValue($entity, $entityId);

                    // Also try setter if available
                    $setter = 'set' . ucfirst($idField);
                    if (method_exists($entity, $setter)) {
                        $entity->$setter($entityId);
                    }

                    // Cache this entity to avoid recursion
                    self::$processedEntities[$dataHash] = $entity;

                    return $entity;
                } catch (\Throwable $e) {
                    $this->logger->error('Error creating entity stub: ' . $e->getMessage());
                }
            }

            if ($entity !== null) {
                // Cache this entity to avoid recursion
                self::$processedEntities[$dataHash] = $entity;
                return $entity;
            }
        }

        // Create a new context to avoid recursion
        $newContext = array_merge($context, [
            'entity_denormalizer_processed' => true
        ]);

        // Store a placeholder to break circular references
        $placeholder = new \stdClass();
        self::$processedEntities[$dataHash] = $placeholder;

        // Let the normalizer handle the actual denormalization
        try {
            $result = $this->normalizer->denormalize($data, $type, $format, $newContext);

            // Update our cache with the real object
            self::$processedEntities[$dataHash] = $result;

            // If this could be an entity, ensure IDs are set
            if (is_object($result) && is_array($data) && isset($data['id'])) {
                try {
                    // Try to set ID directly using the setter if available
                    $setter = 'setId';
                    if (method_exists($result, $setter)) {
                        $result->$setter($data['id']);
                    }

                    // Also try to set it using reflection as a fallback
                    $reflectionClass = new \ReflectionClass($result);
                    if ($reflectionClass->hasProperty('id')) {
                        $property = $reflectionClass->getProperty('id');
                        $property->setAccessible(true);
                        $property->setValue($result, $data['id']);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Error setting entity ID: ' . $e->getMessage());
                }
            }

            return $result;
        } catch (\Throwable $e) {
            // Remove the placeholder on error
            unset(self::$processedEntities[$dataHash]);
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization(mixed $data, string $type, string $format = null, array $context = []): bool
    {
        // Skip if we've already processed this in our denormalizer chain
        if (isset($context['entity_denormalizer_processed'])) {
            return false;
        }

        // Handle entity references
        if (is_array($data) && isset($data['id']) && isset($data['__entity_class'])) {
            return true;
        }

        // Only handle other objects if the wrapped normalizer supports them
        // and we haven't already started processing them
        return !isset($context['entity_denormalizer_processing']) &&
               $this->normalizer->supportsDenormalization($data, $type, $format);
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            'object' => true,            // Supports all object types
            '*' => false,                // But don't call this denormalizer for other types
            \Doctrine\ORM\EntityManager::class => false // Don't handle the EntityManager itself
        ];
    }
}