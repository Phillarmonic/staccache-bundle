# Staccache Bundle Documentation

**A Symfony bundle for efficient Doctrine entity caching with locking support**

## Overview

The Staccache Bundle provides an easy way to implement entity caching in Symfony applications using Redis. It improves application performance by reducing database queries for frequently accessed entities. Key features include:

- Entity-level caching with automatic cache invalidation
- Collection and query result caching
- Distributed locking to prevent race conditions
- Integration with Symfony's form system for automatic cache updates
- Support for controller argument resolution with cached entities
- Compatible with multiple Redis client libraries (phpredis, predis, SNC Redis Bundle)

## Installation

### 1. Install the bundle with Composer

```bash
composer require phillarmonic/staccache-bundle
```

### 2. Register the bundle in your application

For Symfony Flex applications, the bundle will be automatically registered. For manual registration, add it to your `config/bundles.php`:

```php
return [
    // Other bundles...
    Phillarmonic\StaccacheBundle\StaccacheBundle::class => ['all' => true],
];
```

### 3. Configure Redis connection

Create a configuration file at `config/packages/staccache.yaml`:

```yaml
staccache:
    default_ttl: 3600  # Default TTL for cached entities in seconds (1 hour)
    lock_ttl: 30       # Default TTL for entity locks in seconds
    cache_prefix: staccache  # Prefix for cache keys
    auto_cache_on_load: true # Automatically cache entities when loaded

    # Redis connection configuration
    redis:
        driver: auto   # auto, phpredis, or predis
        host: localhost
        port: 6379
        # username: ~  # For Redis 6.0+ with ACL support
        # password: ~
        db: 0
        options:
            timeout: 5.0
            read_timeout: 5.0
            persistent: false
```

## Basic Usage

### Marking Entities as Cacheable

Add the `#[Staccacheable]` attribute to entity classes you want to cache:

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Phillarmonic\StaccacheBundle\Attribute\Staccacheable;

#[ORM\Entity]
#[Staccacheable(ttl: 1800)]  // Cache for 30 minutes
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    // Getters and setters...
}
```

### Configuration Options

The `#[Staccacheable]` attribute accepts the following parameters:

- `ttl`: Time-to-live in seconds for the entity cache (-1 uses the default from configuration)
- `lockOnWrite`: Whether to acquire a lock when updating or deleting the entity (defaults to true)

## Advanced Usage

### Using Repository Methods

The bundle provides a `CachedServiceEntityRepository` base class that adds caching capabilities to your repositories:

```php
<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Persistence\ManagerRegistry;
use Phillarmonic\StaccacheBundle\Cache\EntityCacheManager;
use Phillarmonic\StaccacheBundle\Cache\QueryCacheManager;
use Phillarmonic\StaccacheBundle\Repository\CachedServiceEntityRepository;

class ProductRepository extends CachedServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        EntityCacheManager $cacheManager,
        QueryCacheManager $queryCacheManager
    ) {
        parent::__construct($registry, Product::class, $cacheManager, $queryCacheManager);
    }

    /**
     * Find products by category with cache
     */
    public function findByCategoryWithCache(string $category): array
    {
        // Create a query
        $queryBuilder = $this->createQueryBuilder('p')
            ->where('p.category = :category')
            ->setParameter('category', $category);

        // Execute with cache (key must be unique for this query)
        return $this->executeQueryWithCache(
            $queryBuilder,
            'products_by_category_' . $category,
            3600 // Cache for 1 hour
        );
    }

    /**
     * Example of bypassing cache for a specific operation
     */
    public function findFreshProduct(int $id): ?Product
    {
        return $this->withoutCache()->find($id);
    }
}
```

### Controller Argument Resolution

The bundle automatically resolves entity arguments in controllers, using the cache when possible:

```php
<?php

namespace App\Controller;

use App\Entity\Product;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProductController extends AbstractController
{
    #[Route('/product/{id}', name: 'app_product_show')]
    public function show(Product $product): Response
    {
        // The product is loaded from cache if available
        return $this->render('product/show.html.twig', [
            'product' => $product,
        ]);
    }
}
```

### Cache Bypass Methods

When using the `CachedServiceEntityRepository`, you can bypass the cache for specific operations:

```php
// Bypass entity cache for a single operation
$repository->withoutCache()->find($id);

// Bypass collection cache
$repository->withoutCollectionCache()->findAll();

// Bypass query cache
$repository->withoutQueryCache()->executeQueryWithCache($query, 'cache_key');
```

### Manual Cache Management

You can directly use the `EntityCacheManager` and `QueryCacheManager` services:

```php
<?php

namespace App\Service;

use App\Entity\Product;
use Phillarmonic\StaccacheBundle\Cache\EntityCacheManager;
use Phillarmonic\StaccacheBundle\Cache\QueryCacheManager;

class ProductService
{
    private EntityCacheManager $entityCacheManager;
    private QueryCacheManager $queryCacheManager;

    public function __construct(
        EntityCacheManager $entityCacheManager,
        QueryCacheManager $queryCacheManager
    ) {
        $this->entityCacheManager = $entityCacheManager;
        $this->queryCacheManager = $queryCacheManager;
    }

    public function updateProduct(Product $product): void
    {
        // Update product...

        // Manually update the cache
        $this->entityCacheManager->cacheEntity($product);

        // Invalidate related collection caches
        $this->entityCacheManager->invalidateCollectionCaches(Product::class);

        // Invalidate specific query cache
        $this->queryCacheManager->invalidateQueryCache('featured_products', Product::class);
    }
}
```

## Cache Purging

The bundle provides a command to purge the cache:

```bash
# Purge all caches
bin/console staccache:purge --all

# Purge specific entity class
bin/console staccache:purge "App\Entity\Product"

# Purge only collection caches
bin/console staccache:purge --collection

# Purge only query caches
bin/console staccache:purge --query

# Dry run (show what would be purged without actually purging)
bin/console staccache:purge --all --dry-run
```

## Configuration Reference

Here's the complete configuration reference:

```yaml
# config/packages/staccache.yaml
services:
    staccache.logger:
        alias: monolog.logger.staccache

staccache:
    # Default time-to-live for cached entities in seconds
    default_ttl: 3600

    # Default time-to-live for entity locks in seconds
    lock_ttl: 30

    # Prefix for cache keys
    cache_prefix: staccache

    # Namespace for cacheable entities (optional filter)
    entity_namespace: ~

    # Secret key used for integrity verification
    secret_key: 'aaaaaaaaaaaaaa17652000sss0454500' # Use a secure random value

    # Whether to automatically cache entities when they are loaded
    auto_cache_on_load: true

    # Redis connection configuration
    redis:
        # SNC Redis client name to use (e.g., "default" for snc_redis.default service)
        snc_redis_client: ~

        # Redis driver to use: auto (detect), phpredis (extension), or predis (library)
        driver: phpredis

        # Connection parameters
        scheme: tcp
        host: localhost
        port: 6379
        username: ~
        password: ~
        db: 0

        # Connection options
        options:
            timeout: 5.0
            read_timeout: 5.0
            persistent: false
            persistent_id: ~ # Persistent connection ID for phpredis
```

## Integrations

### SNC Redis Bundle Integration

If you're using the SNC Redis Bundle, you can configure Staccache to use an existing Redis client:

```yaml
staccache:
    redis:
        snc_redis_client: default # Uses the snc_redis.default service
```

### Form Integration

The bundle automatically updates cached entities on form submission:

```php
public function update(Request $request, Product $product, EntityManagerInterface $entityManager): Response
{
    $form = $this->createForm(ProductType::class, $product);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $entityManager->flush();

        // The cache is automatically updated by the FormSubmitCacheListener

        return $this->redirectToRoute('app_product_show', ['id' => $product->getId()]);
    }

    // ...
}
```

## Performance Considerations

### When to Use Caching

Entity caching is most effective for:

- Entities that are frequently read but rarely updated
- Entities that are expensive to load (complex relationships, calculations)
- High-traffic pages that repeatedly access the same entities

### When to Avoid Caching

- Entities that change frequently
- Entities with very large serialized size
- Entities with sensitive data that shouldn't be stored in Redis

### Memory Usage

Monitor your Redis memory usage, especially when caching large collections or entities with many relationships.

## Troubleshooting

### Cache Not Being Used

1. Verify that your entity has the `#[Staccacheable]` attribute
2. Check Redis connection and configuration
3. Use the command `bin/console staccache:purge --all --dry-run` to see if any cache entries exist

### Entity Changes Not Reflected

1. Entity might be cached for longer than expected (check TTL configuration)

2. Cache invalidation might be failing (check logs for errors)

3. Try manually invalidating the cache:
   
   ```php
   $cacheManager->invalidateCache($entity);
   ```

### Redis Connection Issues

1. Verify Redis connection parameters
2. Check if Redis server is running and accessible
3. Ensure proper authentication if Redis requires password

## Best Practices

1. Set appropriate TTL values based on how frequently entities change
2. Use fine-grained cache keys for queries
3. Implement cache warmup for critical entities
4. Monitor Redis memory usage
5. Consider cache versioning for major data structure changes

## Additional Resources

### Logging

The bundle uses a dedicated logging channel `staccache`. Configure it in your Monolog configuration:

```yaml
# config/packages/monolog.yaml
monolog:
    channels:
        - deprecation # Deprecations are logged in the dedicated "deprecation" channel when it exists
        - staccache
    handlers:
        staccache:
            type: stream
            path: "%kernel.logs_dir%/staccache.log"
            level: debug
            channels: [ "staccache" ]
```

Then, configure the logger in your Staccache configuration:

```yaml
# config/packages/staccache.yaml
services:
    staccache.logger:
        alias: monolog.logger.staccache
```

### Performance Monitoring

To monitor cache hit rates and performance, consider implementing metrics collection:

```php
// Track cache hits/misses
$entity = $cacheManager->getFromCache($entityClass, $id);
if ($entity !== null) {
    // Cache hit
    $metrics->increment('cache.hit');
} else {
    // Cache miss
    $metrics->increment('cache.miss');
}
```
