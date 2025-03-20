<?php

namespace Phillarmonic\StaccacheBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use Phillarmonic\StaccacheBundle\Cache\EntityCacheManager;
use Phillarmonic\StaccacheBundle\Cache\QueryCacheManager;
use Phillarmonic\StaccacheBundle\Redis\RedisClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'staccache:purge',
    description: 'Purges the Staccache entity cache',
)]
class StaccachePurgeCommand extends Command
{
    private EntityCacheManager $entityCacheManager;
    private QueryCacheManager $queryCacheManager;
    private RedisClientInterface $redis;
    private ManagerRegistry $doctrine;
    private string $cachePrefix;
    private LoggerInterface $logger;

    public function __construct(
        EntityCacheManager $entityCacheManager,
        QueryCacheManager $queryCacheManager,
        RedisClientInterface $redis,
        ManagerRegistry $doctrine,
        string $cachePrefix,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct();
        $this->entityCacheManager = $entityCacheManager;
        $this->queryCacheManager = $queryCacheManager;
        $this->redis = $redis;
        $this->doctrine = $doctrine;
        $this->cachePrefix = $cachePrefix;
        $this->logger = $logger ?? new NullLogger();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'entityClass',
                InputArgument::OPTIONAL,
                'Specific entity class to purge (e.g. App\\Entity\\User)'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Purge all cache types (entity, collection, query)'
            )
            ->addOption(
                'entity',
                'en',
                InputOption::VALUE_NONE,
                'Purge only entity cache'
            )
            ->addOption(
                'collection',
                'c',
                InputOption::VALUE_NONE,
                'Purge only collection cache'
            )
            ->addOption(
                'query',
                'qr',
                InputOption::VALUE_NONE,
                'Purge only query cache'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be purged without actually purging'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force purge without confirmation'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Staccache Purge Command');

        $entityClass = $input->getArgument('entityClass');
        $all = $input->getOption('all');
        $entityOnly = $input->getOption('entity');
        $collectionOnly = $input->getOption('collection');
        $queryOnly = $input->getOption('query');
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        // If no cache type is specified, default to all
        if (!$entityOnly && !$collectionOnly && !$queryOnly) {
            $all = true;
        }

        // Verify entity class if provided
        if ($entityClass) {
            if (!class_exists($entityClass)) {
                $io->error("Entity class '$entityClass' does not exist.");
                return Command::FAILURE;
            }

            try {
                $metadata = $this->doctrine->getManagerForClass($entityClass)?->getClassMetadata($entityClass);
                if (!$metadata) {
                    $io->error("'$entityClass' is not a valid Doctrine entity.");
                    return Command::FAILURE;
                }
            } catch (\Throwable $e) {
                $io->error("Error verifying entity class: " . $e->getMessage());
                return Command::FAILURE;
            }

            $io->info("Targeting entity class: $entityClass");
        } else {
            $io->info("Targeting all cacheable entities");
        }

        // Show what will be purged
        $this->showPurgeInfo($io, $entityClass, $all, $entityOnly, $collectionOnly, $queryOnly);

        // In dry-run mode, exit here
        if ($dryRun) {
            $io->note('DRY RUN - No cache entries were actually purged');
            return Command::SUCCESS;
        }

        // Confirm unless --force is used
        if (!$force && !$io->confirm('Do you want to proceed with purging?', false)) {
            $io->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        // Start purging operations
        $purgeStart = microtime(true);
        $totalPurged = 0;

        try {
            // Perform the actual purging
            if ($entityClass) {
                // Purge specific entity class
                $totalPurged = $this->purgeEntityClass(
                    $io,
                    $entityClass,
                    $all || $entityOnly,
                    $all || $collectionOnly,
                    $all || $queryOnly
                );
            } else {
                // Purge all entity caches
                $totalPurged = $this->purgeAllCaches(
                    $io,
                    $all || $entityOnly,
                    $all || $collectionOnly,
                    $all || $queryOnly
                );
            }

            $purgeTime = round(microtime(true) - $purgeStart, 2);
            $io->success("Successfully purged $totalPurged cache entries in $purgeTime seconds");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('Error in cache purge command: ' . $e->getMessage());
            $io->error('An error occurred: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Show information about what will be purged
     */
    private function showPurgeInfo(
        SymfonyStyle $io,
        ?string $entityClass,
        bool $all,
        bool $entityOnly,
        bool $collectionOnly,
        bool $queryOnly
    ): void {
        $io->section('Purge Operations');

        $tableRows = [];

        if ($all || $entityOnly) {
            $tableRows[] = ['Entity Cache', $entityClass ?: 'All entities'];
        }

        if ($all || $collectionOnly) {
            $tableRows[] = ['Collection Cache', $entityClass ?: 'All collections'];
        }

        if ($all || $queryOnly) {
            $tableRows[] = ['Query Cache', $entityClass ?: 'All queries'];
        }

        $io->table(['Cache Type', 'Target'], $tableRows);
    }

    /**
     * Purge specific entity class caches
     */
    private function purgeEntityClass(
        SymfonyStyle $io,
        string $entityClass,
        bool $purgeEntity,
        bool $purgeCollection,
        bool $purgeQuery
    ): int {
        $totalPurged = 0;

        // Purge entity instance caches
        if ($purgeEntity) {
            $io->section('Purging Entity Instance Caches');

            $pattern = $this->cachePrefix . ':' . $entityClass . ':*';
            $keys = $this->scanKeys($pattern);

            if (!empty($keys)) {
                $purged = $this->redis->del(...$keys);
                $io->info("Purged $purged entity instance cache entries");
                $totalPurged += $purged;
            } else {
                $io->info("No entity instance cache entries found");
            }
        }

        // Purge collection caches
        if ($purgeCollection) {
            $io->section('Purging Collection Caches');

            // Prepare the entity class for Redis pattern (handle namespace backslashes)
            $escapedEntityClass = str_replace('\\', '\\\\', $entityClass);
            $pattern = $this->cachePrefix . ':collection:' . $escapedEntityClass . '*';
            $keys = $this->scanKeys($pattern);

            if (!empty($keys)) {
                $purged = $this->redis->del(...$keys);
                $io->info("Purged $purged collection cache entries");
                $totalPurged += $purged;
            } else {
                $io->info("No collection cache entries found");
            }
        }

        // Purge query caches
        if ($purgeQuery) {
            $io->section('Purging Query Caches');

            // Prepare the entity class for Redis pattern (handle namespace backslashes)
            $escapedEntityClass = str_replace('\\', '\\\\', $entityClass);
            $pattern = $this->cachePrefix . ':query:' . $escapedEntityClass . ':*';
            $keys = $this->scanKeys($pattern);

            if (!empty($keys)) {
                $purged = $this->redis->del(...$keys);
                $io->info("Purged $purged query cache entries");
                $totalPurged += $purged;
            } else {
                $io->info("No query cache entries found");
            }
        }

        return $totalPurged;
    }

    /**
     * Purge all caches
     */
    private function purgeAllCaches(
        SymfonyStyle $io,
        bool $purgeEntity,
        bool $purgeCollection,
        bool $purgeQuery
    ): int {
        $totalPurged = 0;

        // Purge all entity instances
        if ($purgeEntity) {
            $io->section('Purging All Entity Instance Caches');

            // We need to identify all entity keys - these don't follow a simple pattern
            // but they're not in collection: or query: namespaces
            $allKeys = $this->scanKeys($this->cachePrefix . ':*');
            $entityKeys = array_filter($allKeys, function($key) {
                return strpos($key, $this->cachePrefix . ':collection:') === false &&
                       strpos($key, $this->cachePrefix . ':query:') === false;
            });

            if (!empty($entityKeys)) {
                $purged = $this->redis->del(...$entityKeys);
                $io->info("Purged $purged entity instance cache entries");
                $totalPurged += $purged;
            } else {
                $io->info("No entity instance cache entries found");
            }
        }

        // Purge all collection caches
        if ($purgeCollection) {
            $io->section('Purging All Collection Caches');

            $pattern = $this->cachePrefix . ':collection:*';
            $keys = $this->scanKeys($pattern);

            if (!empty($keys)) {
                $purged = $this->redis->del(...$keys);
                $io->info("Purged $purged collection cache entries");
                $totalPurged += $purged;
            } else {
                $io->info("No collection cache entries found");
            }
        }

        // Purge all query caches
        if ($purgeQuery) {
            $io->section('Purging All Query Caches');

            $pattern = $this->cachePrefix . ':query:*';
            $keys = $this->scanKeys($pattern);

            if (!empty($keys)) {
                $purged = $this->redis->del(...$keys);
                $io->info("Purged $purged query cache entries");
                $totalPurged += $purged;
            } else {
                $io->info("No query cache entries found");
            }
        }

        return $totalPurged;
    }

    /**
     * Scan Redis for keys matching a pattern with improved error handling
     */
    private function scanKeys(string $pattern): array
    {
        $keys = [];
        $this->logger->debug("Scanning for keys with pattern: " . $pattern);

        try {
            // Try direct KEYS command first (better for pattern matching)
            try {
                $directKeys = $this->redis->keys($pattern);
                if (is_array($directKeys) && !empty($directKeys)) {
                    $this->logger->debug("Found " . count($directKeys) . " keys using direct KEYS command");
                    return $directKeys;
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Direct KEYS command failed: " . $e->getMessage() . " - falling back to SCAN");
            }

            // Fall back to SCAN for larger databases
            $iterator = null;
            $scanKeys = $this->redis->scan($iterator, $pattern, 100);

            while ($scanKeys && !empty($scanKeys)) {
                if (is_array($scanKeys)) {
                    array_push($keys, ...$scanKeys);
                } else {
                    $this->logger->warning("Unexpected scan keys format: " . gettype($scanKeys));
                }

                if ($iterator === 0 || $iterator === false) {
                    break;
                }

                $scanKeys = $this->redis->scan($iterator, $pattern, 100);
            }

            // Try pattern variations if nothing found
            if (empty($keys) && strpos($pattern, '\\\\') !== false) {
                // Try alternative pattern formats
                $altPattern = str_replace('\\\\', '*', $pattern);
                $this->logger->debug("No keys found. Trying alternative pattern: " . $altPattern);

                $altKeys = $this->redis->keys($altPattern);
                if (is_array($altKeys) && !empty($altKeys)) {
                    $this->logger->debug("Found " . count($altKeys) . " keys with alternative pattern");
                    $keys = $altKeys;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error in scanKeys: ' . $e->getMessage());
        }

        return is_array($keys) ? $keys : [];
    }
}