<?php
declare(strict_types=1);
namespace Ronu\RestGenericClass\Core\Traits;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Trait ValidatesExistenceInDatabase
 *
 * Provides reusable methods for validating array of IDs against database tables
 * with built-in caching support and comprehensive error handling.
 *
 * @package Src\Core\Traits
 */
trait ValidatesExistenceInDatabase
{
    /**
     * Cache TTL in seconds.
     * Reads from config('rest-generic-class.validation.cache_ttl'), falls back to 3600.
     *
     * @var int
     */
    protected int $validationCacheTtl = 3600;

    /**
     * Enable/disable caching for validation queries.
     * Reads from config('rest-generic-class.validation.cache_enabled'), falls back to true.
     *
     * @var bool
     */
    protected bool $enableValidationCache = true;

    /**
     * Cache key prefix for validation queries.
     * Reads from config('rest-generic-class.validation.cache_prefix'), falls back to 'validation'.
     *
     * @var string
     */
    protected string $cacheKeyPrefix = 'validation';

    /**
     * Database connection name.
     * Reads from config('rest-generic-class.validation.connection'), falls back to 'db'.
     *
     * @var string
     */
    protected string $connection = 'db';

    /**
     * Initialize validation properties from configuration.
     * Called automatically via Laravel's trait boot convention (bootTraitName).
     * Values set explicitly in the using class take precedence over config values.
     *
     * @return void
     */
    public function initializeValidatesExistenceInDatabase(): void
    {
        $this->validationCacheTtl = (int) config('rest-generic-class.validation.cache_ttl', $this->validationCacheTtl);
        $this->enableValidationCache = (bool) config('rest-generic-class.validation.cache_enabled', $this->enableValidationCache);
        $this->cacheKeyPrefix = (string) config('rest-generic-class.validation.cache_prefix', $this->cacheKeyPrefix);
        $this->connection = (string) config('rest-generic-class.validation.connection', $this->connection);
    }

    /**
     * Validate that all IDs exist in the specified table with optional conditions
     *
     * @param array<int|string> $ids Array of IDs to validate
     * @param string $table Database table name
     * @param string $column Column name to check against (default: 'id')
     * @param array<string, mixed> $additionalConditions Key-value pairs for WHERE clauses
     * @return array sucess=> bool, missing_ids => array, existing_ids => array
     *
     * @example
     * // Simple existence check
     * $this->validateIdsExistInTable([1, 2, 3], 'users');
     *
     * // With status condition
     * $this->validateIdsExistInTable([1, 2], 'users', 'id', ['status' => 'active']);
     *
     * // Multiple conditions
     * $this->validateIdsExistInTable([1, 2], 'users', 'id', [
     *     'status' => 'active',
     *     'verified' => true
     * ]);
     */
    public function validateIdsExistInTable(
        array $ids,
        string $table,
        string $column = 'id',
        array $additionalConditions = []
    ): mixed {
        // Empty array is considered valid
        if (empty($ids)) {
            return true;
        }

        // Remove duplicates and filter out null/empty values
        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id !== null && $id !== '')));

        if (empty($ids)) {
            return ['success'=>'false','error'=>'No valid IDs provided','missing_ids'=>[],'existing_ids'=>[]];
        }

        try {
            $validIds = $this->getValidIdsFromTable($table, $column, $additionalConditions);

            // Check if all requested IDs exist in valid IDs
            $missingIds = array_diff($ids, $validIds);
            $existingIds = array_intersect($ids, $validIds);
            $success=count($ids)!==count($missingIds);
            return ['success'=>$success,'missing_ids'=>$missingIds,'existing_ids'=>$existingIds];
        } catch (\Exception $e) {
            $this->logValidationError('validateIdsExistInTable', $table, $e);
            return false;
        }
    }

    /**
     * Validate IDs exist with specific status (convenience method)
     *
     * @param array<int|string> $ids Array of IDs to validate
     * @param string $table Database table name
     * @param string $status Status value to check (default: 'active')
     * @param string $statusColumn Status column name (default: 'status')
     * @return bool True if all IDs exist with the specified status
     *
     * @example
     * // Validate active roles
     * $this->validateIdsExistWithStatus([1, 2], 'roles');
     *
     * // Validate published posts
     * $this->validateIdsExistWithStatus([10, 20], 'posts', 'published', 'state');
     */
    public function validateIdsExistWithStatus(
        array $ids,
        string $table,
        string $status = 'active',
        string $statusColumn = 'status'
    ): mixed {
        return $this->validateIdsExistInTable(
            $ids,
            $table,
            'id',
            [$statusColumn => $status]
        );
    }

    /**
     * Validate IDs exist and are not soft deleted
     *
     * @param array<int|string> $ids Array of IDs to validate
     * @param string $table Database table name
     * @param string $column Column name to check (default: 'id')
     * @return bool True if all IDs exist and are not deleted
     *
     * @example
     * $this->validateIdsExistNotDeleted([1, 2, 3], 'departments');
     */
    public function validateIdsExistNotDeleted(
        array $ids,
        string $table,
        string $column = 'id'
    ): bool {
        if (empty($ids)) {
            return true;
        }

        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id !== null && $id !== '')));

        if (empty($ids)) {
            return true;
        }

        try {
            $cacheKey = $this->buildCacheKey($table, $column, ['not_deleted' => true]);

            $validIds = $this->getCachedData($cacheKey, function () use ($table, $column) {
                return DB::connection($this->connection)->table($table)
                    ->whereNull('deleted_at')
                    ->pluck($column)
                    ->toArray();
            });

            $missingIds = array_diff($ids, $validIds);

            return empty($missingIds);
        } catch (\Exception $e) {
            $this->logValidationError('validateIdsExistNotDeleted', $table, $e);
            return false;
        }
    }

    /**
     * Get array of IDs that don't exist in the table (for error messages)
     *
     * @param array<int|string> $ids Array of IDs to check
     * @param string $table Database table name
     * @param string $column Column name to check (default: 'id')
     * @param array<string, mixed> $additionalConditions Key-value pairs for WHERE clauses
     * @return array<int|string> Array of missing IDs
     *
     * @example
     * $missing = $this->getMissingIds([1, 2, 999], 'roles', 'id', ['status' => 'active']);
     * // Returns: [999]
     *
     * if (!empty($missing)) {
     *     $this->errorMessage = 'Invalid role IDs: ' . implode(', ', $missing);
     * }
     */
    public function getMissingIds(
        array $ids,
        string $table,
        string $column = 'id',
        array $additionalConditions = []
    ): array {
        if (empty($ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id !== null && $id !== '')));

        if (empty($ids)) {
            return [];
        }

        try {
            $validIds = $this->getValidIdsFromTable($table, $column, $additionalConditions);

            return array_values(array_diff($ids, $validIds));
        } catch (\Exception $e) {
            $this->logValidationError('getMissingIds', $table, $e);
            return $ids; // Return all IDs as missing on error
        }
    }

    /**
     * Validate IDs using a custom query callback
     *
     * @param array<int|string> $ids Array of IDs to validate
     * @param Closure $queryCallback Callback that receives query builder and returns modified query
     * @param string $column Column name to check (default: 'id')
     * @return bool True if all IDs pass the custom validation
     *
     * @example
     * $this->validateIdsWithCustomQuery([1, 2], function($query) {
     *     return $query->where('price', '>', 100)
     *                  ->where('stock', '>', 0);
     * });
     */
    public function validateIdsWithCustomQuery(
        array $ids,
        Closure $queryCallback,
        string $column = 'id'
    ): bool {
        if (empty($ids)) {
            return true;
        }

        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id !== null && $id !== '')));

        if (empty($ids)) {
            return true;
        }

        try {
            // Execute the callback to get the query builder
            $query = $queryCallback( DB::connection($this->connection)->query());

            if (!$query instanceof \Illuminate\Database\Query\Builder) {
                throw new \InvalidArgumentException('Query callback must return a Query Builder instance');
            }

            $count = $query->whereIn($column, $ids)->count();

            return $count === count($ids);
        } catch (\Exception $e) {
            $this->logValidationError('validateIdsWithCustomQuery', 'custom_query', $e);
            return false;
        }
    }

    /**
     * Validate IDs exist with multiple status values (OR condition)
     *
     * @param array<int|string> $ids Array of IDs to validate
     * @param string $table Database table name
     * @param array<string> $statuses Array of acceptable status values
     * @param string $statusColumn Status column name (default: 'status')
     * @return bool True if all IDs exist with any of the specified statuses
     *
     * @example
     * // Validate users that are either 'active' or 'pending'
     * $this->validateIdsExistWithAnyStatus([1, 2], 'users', ['active', 'pending']);
     */
    public function validateIdsExistWithAnyStatus(
        array $ids,
        string $table,
        array $statuses,
        string $statusColumn = 'status'
    ): bool {
        if (empty($ids) || empty($statuses)) {
            return empty($ids); // True if no IDs to validate
        }

        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id !== null && $id !== '')));

        if (empty($ids)) {
            return true;
        }

        try {
            $cacheKey = $this->buildCacheKey($table, 'id', [
                $statusColumn => $statuses
            ]);

            $validIds = $this->getCachedData($cacheKey, function () use ($table, $statusColumn, $statuses) {
                return  DB::connection($this->connection)->table($table)
                    ->whereIn($statusColumn, $statuses)
                    ->pluck('id')
                    ->toArray();
            });

            $missingIds = array_diff($ids, $validIds);

            return empty($missingIds);
        } catch (\Exception $e) {
            $this->logValidationError('validateIdsExistWithAnyStatus', $table, $e);
            return false;
        }
    }

    /**
     * Validate IDs with date range conditions
     *
     * @param array<int|string> $ids Array of IDs to validate
     * @param string $table Database table name
     * @param string $dateColumn Date column name
     * @param string|null $startDate Start date (inclusive)
     * @param string|null $endDate End date (inclusive)
     * @param array<string, mixed> $additionalConditions Additional WHERE conditions
     * @return bool True if all IDs exist within the date range
     *
     * @example
     * // Validate orders created in the last 30 days
     * $this->validateIdsExistWithDateRange(
     *     [1, 2, 3],
     *     'orders',
     *     'created_at',
     *     now()->subDays(30)->toDateString(),
     *     now()->toDateString()
     * );
     */
    public function validateIdsExistWithDateRange(
        array $ids,
        string $table,
        string $dateColumn,
        ?string $startDate = null,
        ?string $endDate = null,
        array $additionalConditions = []
    ): bool {
        if (empty($ids)) {
            return true;
        }

        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id !== null && $id !== '')));

        if (empty($ids)) {
            return true;
        }

        try {
            $query =  DB::connection($this->connection)->table($table)->whereIn('id', $ids);

            if ($startDate !== null) {
                $query->where($dateColumn, '>=', $startDate);
            }

            if ($endDate !== null) {
                $query->where($dateColumn, '<=', $endDate);
            }

            foreach ($additionalConditions as $column => $value) {
                $query->where($column, $value);
            }

            $count = $query->count();

            return $count === count($ids);
        } catch (\Exception $e) {
            $this->logValidationError('validateIdsExistWithDateRange', $table, $e);
            return false;
        }
    }

    /**
     * Get valid IDs from table with caching support
     *
     * @param string $table Database table name
     * @param string $column Column name to retrieve
     * @param array<string, mixed> $conditions WHERE conditions
     * @return array<int|string> Array of valid IDs
     */
    private function getValidIdsFromTable(
        string $table,
        string $column = 'id',
        array $conditions = []
    ): array {
        $cacheKey = $this->buildCacheKey($table, $column, $conditions);

        return $this->getCachedData($cacheKey, function () use ($table, $column, $conditions) {
            $query =  DB::connection($this->connection)->table($table);

            foreach ($conditions as $col => $value) {
                if (is_array($value)) {
                    $query->whereIn($col, $value);
                } else {
                    $query->where($col, $value);
                }
            }

            return $query->pluck($column)->toArray();
        });
    }

    /**
     * Build cache key for validation queries
     *
     * @param string $table Table name
     * @param string $column Column name
     * @param array<string, mixed> $conditions Conditions array
     * @return string Cache key
     */
    private function buildCacheKey(string $table, string $column, array $conditions): string
    {
        $conditionsHash = md5(serialize($conditions));

        return sprintf(
            '%s:%s:%s:%s',
            $this->cacheKeyPrefix,
            $table,
            $column,
            $conditionsHash
        );
    }

    /**
     * Get data from cache or execute callback
     *
     * @param string $cacheKey Cache key
     * @param Closure $callback Callback to execute if cache miss
     * @return mixed Cached or fresh data
     */
    private function getCachedData(string $cacheKey, Closure $callback): mixed
    {
        if (!$this->enableValidationCache) {
            return $callback();
        }

        try {
            return Cache::remember($cacheKey, $this->validationCacheTtl, $callback);
        } catch (\Exception $e) {
            // If caching fails, execute callback directly
            Log::warning('Validation cache failed, executing query directly', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    /**
     * Clear validation cache for specific table
     *
     * @param string $table Table name
     * @return bool True if cache was cleared successfully
     */
    public function clearValidationCache(string $table): bool
    {
        try {
            $pattern = sprintf('%s:%s:*', $this->cacheKeyPrefix, $table);

            // For Redis cache driver
            if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
                $keys = Cache::getStore()->getRedis()->keys($pattern);
                foreach ($keys as $key) {
                    Cache::forget($key);
                }
                return true;
            }

            // For other cache drivers, we can't efficiently clear by pattern
            // Caller should handle cache invalidation on model events
            return false;
        } catch (\Exception $e) {
            $this->logValidationError('clearValidationCache', $table, $e);
            return false;
        }
    }

    /**
     * Clear all validation cache
     *
     * @return bool True if cache was cleared successfully
     */
    protected function clearAllValidationCache(): bool
    {
        try {
            if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
                $pattern = sprintf('%s:*', $this->cacheKeyPrefix);
                $keys = Cache::getStore()->getRedis()->keys($pattern);

                foreach ($keys as $key) {
                    Cache::forget($key);
                }

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to clear all validation cache', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Set validation cache TTL
     *
     * @param int $seconds Cache TTL in seconds
     * @return self
     */
    protected function setValidationCacheTtl(int $seconds): self
    {
        $this->validationCacheTtl = $seconds;
        return $this;
    }

    /**
     * Enable validation caching
     *
     * @return self
     */
    protected function enableValidationCaching(): self
    {
        $this->enableValidationCache = true;
        return $this;
    }

    /**
     * Disable validation caching
     *
     * @return self
     */
    protected function disableValidationCaching(): self
    {
        $this->enableValidationCache = false;
        return $this;
    }

    /**
     * Log validation error
     *
     * @param string $method Method name where error occurred
     * @param string $table Table name
     * @param \Exception $exception Exception instance
     * @return void
     */
    private function logValidationError(string $method, string $table, \Exception $exception): void
    {
        Log::error('Database validation error', [
            'method' => $method,
            'table' => $table,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}