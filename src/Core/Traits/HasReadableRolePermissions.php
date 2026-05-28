<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Support\Collection;

trait HasReadableRolePermissions
{
    /**
     * Default implementation of ProvidesRolePermissions.
     *
     * Returns the role's enabled permissions. Implementers should declare
     * 'implements ProvidesRolePermissions' on their Role class; they may override
     * this method if their role exposes permissions through a different relation.
     */
    public function provideRolePermissions(): Collection
    {
        $perms = $this->enabled_permissions;

        return $perms instanceof Collection ? $perms : collect($perms);
    }

    /**
     * Get all permissions for this role, merged with non-restricted global permissions.
     */
    public function allPermissions(): Collection
    {
        $permissionClass = app(config('permission.models.permission'));
        /** @var \Illuminate\Database\Eloquent\Collection $globalPerms */
        $globalPerms = $permissionClass::query()
            ->notRestricted()
            ->where('guard_name', $this->guard_name)
            ->get();
        $this->setRelation(
            'permissions',
            $this->permissions->concat($globalPerms)->unique('id')->values()
        );

        return $this->permissions; // eager load recommended outside
    }

    /**
     * Filter by guard/module/entity.
     */
    public function permissionsFiltered(?string $guard = null, ?array $modules = null, ?array $entities = null): Collection
    {
        return $this->permissions->filter(function ($perm) use ($guard, $modules, $entities) {
            if ($guard && $perm->guard_name !== $guard) return false;
            if (!$perm->restrict) return true;
            if ($modules && count($modules) > 0) {
                if (!in_array($perm->module ?? null, $modules, true)) return false;
            }

            if ($entities && count($entities) > 0) {
                // entity expected in $perm->model (según tu schema)
                $entity = $perm->model ?? null;
                $ok = false;
                foreach ($entities as $raw) {
                    $needle = $raw;
                    // soporta module.entity => ignoramos módulo aquí; ya filtrado arriba
                    if (str_contains($raw, '.')) {
                        $needle = substr($raw, strpos($raw, '.') + 1);
                    }
                    if ($entity && strcasecmp($entity, $needle) === 0) { $ok = true; break; }
                }
                if (!$ok) return false;
            }

            return true;
        })->values();
    }
}
