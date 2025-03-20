<?php

namespace Phillarmonic\StaccacheBundle\Serializer;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;

/**
 * Custom denormalizer to ensure entity IDs are properly set during deserialization
 */
class IdAwareEntityDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    private ManagerRegistry $doctrine;
    private static array $processing = [];

    public function __construct(
        ManagerRegistry $doctrine,
        private readonly ?LoggerInterface $logger
    )
    {
        $this->doctrine = $doctrine;
    }

    /**
     * {@inheritdoc}
     */
    public function denormalize(mixed $data, string $type, string $format = null, array $context = []): mixed
    {
        // Generate a unique key for this denormalization operation
        $processingKey = md5(json_encode($data) . $type);

        // Check if we're already processing this data to prevent recursion
        if (isset(self::$processing[$processingKey])) {
            return self::$processing[$processingKey];
        }

        // Mark as being processed
        self::$processing[$processingKey] = new \stdClass(); // Placeholder

        // Add a flag to context to prevent other denormalizers from looping back to us
        $newContext = array_merge($context, ['id_aware_entity_denormalizer_processed' => true]);

        try {
            // Allow the next denormalizer to create/populate the object
            $object = $this->denormalizer->denormalize($data, $type, $format, $newContext);

            // Update our processing registry with the real object
            self::$processing[$processingKey] = $object;

            // Check if this is a Doctrine entity
            if (!$this->isTransient($type)) {
                $this->setEntityId($object, $type, $data);
            }

            return $object;
        } catch (\Throwable $e) {
            // Clean up on exception
            unset(self::$processing[$processingKey]);
            throw $e;
        }
    }

    /**
     * Set entity ID and register with UnitOfWork
     */
    private function setEntityId(object $object, string $type, array $data): void
    {
        try {
            $metadata = $this->doctrine->getManager()->getClassMetadata($type);
            $idFields = $metadata->getIdentifierFieldNames();

            // For each identifier field, check if the data has it
            foreach ($idFields as $idField) {
                if (isset($data[$idField])) {
                    // Force set the ID using reflection (bypassing any protection)
                    $reflectionProperty = $metadata->getReflectionProperty($idField);
                    $reflectionProperty->setAccessible(true);
                    $reflectionProperty->setValue($object, $data[$idField]);

                    // Also ensure the ID is set in any getter/setter if available
                    $setter = 'set' . ucfirst($idField);
                    if (method_exists($object, $setter)) {
                        $object->$setter($data[$idField]);
                    }
                }
            }

            // For composite IDs, make sure they're all set
            $shouldRegister = false;
            if (count($idFields) > 1) {
                $allIdsSet = true;
                foreach ($idFields as $idField) {
                    if (!isset($data[$idField])) {
                        $allIdsSet = false;
                        break;
                    }
                }
                $shouldRegister = $allIdsSet;
            } else if (isset($data[$idFields[0]])) {
                $shouldRegister = true;
            }

            if ($shouldRegister) {
                $this->registerWithUnitOfWork($object, $type);
            }
        } catch (\Throwable $e) {
            // Log error but continue with deserialization
            $this->logger->error('Error in IdAwareEntityDenormalizer: ' . $e->getMessage());
        }
    }

    /**
     * Check if type is a transient entity
     */
    private function isTransient(string $class): bool
    {
        try {
            return $this->doctrine->getManager()->getMetadataFactory()->isTransient($class);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Register the entity with Doctrine's UnitOfWork to ensure proper tracking
     */
    private function registerWithUnitOfWork(object $entity, string $type): void
    {
        try {
            $em = $this->doctrine->getManagerForClass($type);
            if (!$em) {
                return;
            }

            $uow = $em->getUnitOfWork();
            $metadata = $em->getClassMetadata($type);

            // Only register if not already in identity map
            if (!$uow->isInIdentityMap($entity)) {
                // Collect current field values for change tracking
                $currentData = [];
                foreach ($metadata->getFieldNames() as $fieldName) {
                    if (!$metadata->isIdentifier($fieldName)) {
                        $currentData[$fieldName] = $metadata->getFieldValue($entity, $fieldName);
                    }
                }

                // Get identifier values
                $identifiers = $metadata->getIdentifierValues($entity);

                // Only register if we have valid identifiers
                if (!empty($identifiers)) {
                    $uow->registerManaged($entity, $identifiers, $currentData);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error registering entity with UnitOfWork: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization(mixed $data, string $type, string $format = null, array $context = []): bool
    {
        // Skip if we've already processed this in our denormalizer chain
        if (isset($context['id_aware_entity_denormalizer_processed'])) {
            return false;
        }

        // Check if this is a Doctrine entity
        try {
            return is_array($data) && !$this->isTransient($type);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            'object' => true, // We handle all object types that are Doctrine entities
            '*' => false,     // We don't want to be called for everything
        ];
    }
}