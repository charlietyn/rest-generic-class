<?php

declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Validation;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseExistenceChecker
{
    public function __construct(
        private readonly string $connection = 'db',
        private readonly int $cacheTtl = 3600,
        private readonly bool $cacheEnabled = true,
        private readonly string $cacheKeyPrefix = 'validation'
    ) {
    }

    public function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            $ids,
            static fn ($id): bool => $id !== null && $id !== ''
        )));
    }

    public function getValidIdsFromTable(
        string $table,
        string $column = 'id',
        array $conditions = []
    ): array {
        $cacheKey = $this->buildCacheKey($table, $column, $conditions);

        return $this->getCachedData($cacheKey, function () use ($table, $column, $conditions) {
            $query = DB::connection($this->connection)->table($table);
            $this->applyConditions($query, $conditions);

            return $query->pluck($column)->toArray();
        });
    }

    public function idsExistNotDeleted(
        array $ids,
        string $table,
        string $column = 'id',
        array $conditions = [],
        ?string $deletedAtColumn = 'deleted_at'
    ): bool {
        $validIds = $this->getValidIdsNotDeleted($table, $column, $conditions, $deletedAtColumn);

        return empty(array_diff($ids, $validIds));
    }

    public function idsExistWithCustomQuery(
        array $ids,
        Closure $queryCallback,
        string $column = 'id',
        array $conditions = []
    ): bool {
        $query = $queryCallback(DB::connection($this->connection)->query());

        if (!$query instanceof Builder) {
            throw new \InvalidArgumentException('Query callback must return a Query Builder instance');
        }

        $this->applyConditions($query, $conditions);

        return $query->whereIn($column, $ids)->count() === count($ids);
    }

    public function idsExistWithAnyStatus(
        array $ids,
        string $table,
        array $statuses,
        string $statusColumn = 'status',
        array $conditions = []
    ): bool {
        $cacheKey = $this->buildCacheKey($table, 'id', array_merge(
            [$statusColumn => $statuses],
            $conditions
        ));

        $validIds = $this->getCachedData($cacheKey, function () use ($table, $statusColumn, $statuses, $conditions) {
            $query = DB::connection($this->connection)
                ->table($table)
                ->whereIn($statusColumn, $statuses);

            $this->applyConditions($query, $conditions);

            return $query->pluck('id')->toArray();
        });

        return empty(array_diff($ids, $validIds));
    }

    public function idsExistWithDateRange(
        array $ids,
        string $table,
        string $dateColumn,
        ?string $startDate = null,
        ?string $endDate = null,
        array $conditions = []
    ): bool {
        $query = DB::connection($this->connection)
            ->table($table)
            ->whereIn('id', $ids);

        if ($startDate !== null) {
            $query->where($dateColumn, '>=', $startDate);
        }

        if ($endDate !== null) {
            $query->where($dateColumn, '<=', $endDate);
        }

        $this->applyConditions($query, $conditions);

        return $query->count() === count($ids);
    }

    public function clearValidationCache(string $table): bool
    {
        $pattern = sprintf('%s:%s:*', $this->cacheKeyPrefix, $table);

        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $keys = Cache::getStore()->getRedis()->keys($pattern);
            foreach ($keys as $key) {
                Cache::forget($key);
            }

            return true;
        }

        return false;
    }

    public function clearAllValidationCache(): bool
    {
        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $pattern = sprintf('%s:*', $this->cacheKeyPrefix);
            $keys = Cache::getStore()->getRedis()->keys($pattern);

            foreach ($keys as $key) {
                Cache::forget($key);
            }

            return true;
        }

        return false;
    }

    public function buildCacheKey(string $table, string $column, array $conditions): string
    {
        return sprintf(
            '%s:%s:%s:%s',
            $this->cacheKeyPrefix,
            $table,
            $column,
            md5(serialize($conditions))
        );
    }

    private function getValidIdsNotDeleted(
        string $table,
        string $column,
        array $conditions,
        ?string $deletedAtColumn
    ): array {
        $cacheKey = $this->buildCacheKey($table, $column, array_merge(
            ['not_deleted' => $deletedAtColumn ?? false],
            $conditions
        ));

        return $this->getCachedData($cacheKey, function () use ($table, $column, $conditions, $deletedAtColumn) {
            $query = DB::connection($this->connection)->table($table);

            if ($deletedAtColumn !== null) {
                $query->whereNull($deletedAtColumn);
            }

            $this->applyConditions($query, $conditions);

            return $query->pluck($column)->toArray();
        });
    }

    private function applyConditions(Builder $query, array $conditions): void
    {
        foreach ($conditions as $column => $value) {
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, $value);
            }
        }
    }

    private function getCachedData(string $cacheKey, Closure $callback): mixed
    {
        if (!$this->cacheEnabled) {
            return $callback();
        }

        try {
            return Cache::remember($cacheKey, $this->cacheTtl, $callback);
        } catch (\Exception $e) {
            Log::warning('Validation cache failed, executing query directly', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }
}
