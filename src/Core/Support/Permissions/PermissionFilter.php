<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Support\Collection;

final class PermissionFilter
{
    public function filter(
        Collection $permissions,
        ?string $guard = null,
        ?array $modules = null,
        ?array $entities = null,
        bool $includeUnrestricted = false
    ): Collection {
        return $permissions->filter(function ($permission) use ($guard, $modules, $entities, $includeUnrestricted): bool {
            if ($guard && ($permission->guard_name ?? null) !== $guard) {
                return false;
            }

            if ($includeUnrestricted && !$this->isRestricted($permission)) {
                return true;
            }

            if ($modules && count($modules) > 0 && !in_array($permission->module ?? null, $modules, true)) {
                return false;
            }

            if ($entities && count($entities) > 0 && !$this->matchesAnyEntity($permission->model ?? null, $entities)) {
                return false;
            }

            return true;
        })->values();
    }

    private function isRestricted(object $permission): bool
    {
        return (bool)($permission->restrict ?? true);
    }

    private function matchesAnyEntity(?string $entity, array $entities): bool
    {
        if (!$entity) {
            return false;
        }

        foreach ($entities as $raw) {
            $needle = (string)$raw;

            if (str_contains($needle, '.')) {
                $needle = substr($needle, strpos($needle, '.') + 1);
            }

            if (strcasecmp($entity, $needle) === 0) {
                return true;
            }
        }

        return false;
    }
}
