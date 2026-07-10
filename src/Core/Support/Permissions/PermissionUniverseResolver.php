<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the full universe of system permissions used as the compression base.
 *
 * Extracted from HasReadableUserPermissions so the query that feeds
 * effectivePermissionsCompressed() lives in one collaborator (SRP) and can be
 * exercised in isolation, independent of the trait/model it used to hang off.
 */
final class PermissionUniverseResolver
{
    /**
     * All system permissions matching the given guard/module/entity filters.
     */
    public function universe(?string $guard = null, ?array $modules = null, ?array $entities = null): Collection
    {
        return $this->applyFilters($this->baseQuery(), $guard, $modules, $entities)->get();
    }

    /**
     * Apply the guard/module/entity constraints onto a permission query.
     *
     * Kept as a public seam so the filtering logic (including the `module.entity`
     * shorthand) can be verified against a real query builder without touching
     * the configured permission model.
     */
    public function applyFilters(Builder $query, ?string $guard = null, ?array $modules = null, ?array $entities = null): Builder
    {
        return $query
            ->when($guard, fn($q) => $q->where('guard_name', $guard))
            ->when($modules && count($modules) > 0, fn($q) => $q->whereIn('module', $modules))
            ->when($entities && count($entities) > 0, function ($query) use ($entities) {
                $query->where(function ($outer) use ($entities) {
                    foreach ($entities as $raw) {
                        $module = null;
                        $entity = $raw;

                        if (str_contains($raw, '.')) {
                            [$module, $entity] = explode('.', $raw, 2);
                        }

                        $outer->orWhere(function ($sub) use ($module, $entity) {
                            $sub->whereRaw('LOWER(model) = ?', [strtolower($entity)]);

                            if ($module !== null && $module !== '') {
                                $sub->whereRaw('LOWER(module) = ?', [strtolower($module)]);
                            }
                        });
                    }
                });
            });
    }

    /**
     * Base query for the configured permission model.
     */
    protected function baseQuery(): Builder
    {
        return app(config('permission.models.permission'))->newQuery();
    }
}
