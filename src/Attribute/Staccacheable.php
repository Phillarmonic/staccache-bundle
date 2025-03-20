<?php
namespace Phillarmonic\StaccacheBundle\Attribute;

/**
 * Attribute to mark entity classes or properties as cacheable
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY)]
class Staccacheable
{
    public function __construct(
        public int $ttl = -1, // -1 means use default from configuration
        public bool $lockOnWrite = true
    ) {
    }
}