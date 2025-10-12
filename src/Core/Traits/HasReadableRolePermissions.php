<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Support\Collection;

trait HasReadableRolePermissions
{
    /**
     * Get all permissions for this role (already provided by Spatie as relation).
     */
    public function allPermissions(): Collection
    {
        $permissionClass = app(config('permission.models.permission'));
        /** @var \Illuminate\Database\Eloquent\Collection $globalPerms */
        $globalPerms = $permissionClass::query()
            ->notRestricted()
            ->when($guard, fn($q) => $q->where('guard_name', $this->guard_name))
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
