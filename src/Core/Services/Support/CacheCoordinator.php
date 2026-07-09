<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Ronu\RestGenericClass\Core\Contracts\HasRestCache;

class CacheCoordinator
{
    private Closure $relationVersionsResolver;

    public function __construct(
        private Model $model,
        private string $prefix,
        private ?bool $cacheable,
        private ?int $cacheTtl,
        private array $cacheableOperations,
        callable $relationVersionsResolver
    ) {
        $this->relationVersionsResolver = Closure::fromCallable($relationVersionsResolver);
    }

    public function shouldUse(string $operation, array $params): bool
    {
        if ($this->cacheable === false) {
            return false;
        }

        if ($this->cacheable === null) {
            $cacheConfig = config('rest-generic-class.cache', []);
            if (!($cacheConfig['enabled'] ?? false)) {
                return false;
            }
        }

        $cacheableMethods = !empty($this->cacheableOperations)
            ? $this->cacheableOperations
            : (config('rest-generic-class.cache.cacheable_methods') ?? []);

        if (!in_array($operation, $cacheableMethods, true)) {
            return false;
        }

        if (array_key_exists('cache', $params) && $params['cache'] === false) {
            return false;
        }

        return true;
    }

    public function remember(string $operation, array $params, callable $callback): mixed
    {
        return $this->store()->remember(
            $this->key($operation, $params),
            $this->resolveTtl($operation, $params),
            $callback
        );
    }

    public function store()
    {
        $store = config('rest-generic-class.cache.store');
        return $store ? Cache::store($store) : Cache::store();
    }

    public function modelVersion(): int
    {
        $version = $this->store()->get($this->versionKey(), 1);
        return is_numeric($version) ? (int)$version : 1;
    }

    public function bumpVersion(): void
    {
        if (!config('rest-generic-class.cache.enabled', false) && $this->cacheable !== true) {
            return;
        }

        $store = $this->store();

        $currentVersion = $store->get($this->versionKey(), 1);
        $store->forever($this->versionKey(), ((int)$currentVersion) + 1);

        $invalidates = $this->model instanceof HasRestCache
            ? $this->model->getCacheInvalidates()
            : $this->cacheInvalidatesFromConstant();

        foreach ($invalidates as $relatedModelClass) {
            $relatedKey = $this->versionKey($relatedModelClass);
            $relatedVersion = $store->get($relatedKey, 1);
            $store->forever($relatedKey, ((int)$relatedVersion) + 1);
        }
    }

    public function versionKey(?string $modelClass = null): string
    {
        return $this->prefix . ':version:' . ($modelClass ?? get_class($this->model));
    }

    public function ttl(string $operation, array $params)
    {
        return $this->resolveTtl($operation, $params);
    }

    public function key(string $operation, array $params): string
    {
        $request = $this->resolveRequest();
        $route = $request?->route();
        $headersToVary = config('rest-generic-class.cache.vary.headers', []);
        $varyHeaders = [];

        foreach ($headersToVary as $header) {
            $varyHeaders[$header] = $request?->header($header);
        }

        $relationVersionsResolver = $this->relationVersionsResolver;
        $fingerprint = [
            'op' => $operation,
            'model' => get_class($this->model),
            'route' => $route?->getName() ?? $request?->path() ?? 'cli',
            'method' => $request?->method(),
            'query' => $request?->query(),
            'headers' => $varyHeaders,
            'user' => $this->resolveUserId(),
            'params' => $params,
            'version' => $this->modelVersion(),
            'rel_versions' => $relationVersionsResolver($params),
        ];

        return $this->prefix . ':' . sha1(json_encode($fingerprint));
    }

    private function resolveTtl(string $operation, array $params)
    {
        if (isset($params['cache_ttl']) && is_numeric($params['cache_ttl'])) {
            return now()->addSeconds((int)$params['cache_ttl']);
        }

        if ($this->cacheTtl !== null) {
            return now()->addSeconds($this->cacheTtl);
        }

        $defaultTtl = (int)config('rest-generic-class.cache.ttl', 60);
        $ttlByMethod = (int)config('rest-generic-class.cache.ttl_by_method.' . $operation, $defaultTtl);

        return now()->addSeconds($ttlByMethod);
    }

    private function cacheInvalidatesFromConstant(): array
    {
        $modelClass = get_class($this->model);

        return defined($modelClass . '::CACHE_INVALIDATES')
            ? constant($modelClass . '::CACHE_INVALIDATES')
            : [];
    }

    private function resolveRequest(): mixed
    {
        try {
            return request();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveUserId(): mixed
    {
        try {
            return auth()->id();
        } catch (\Throwable) {
            return null;
        }
    }
}
