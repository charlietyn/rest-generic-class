<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\PermissionRegistrar;

trait HasReadableUserPermissions
{
    public function directPermissions(): Collection
    {
        // Spatie already has: $this->getDirectPermissions()
        return $this->getDirectPermissions();
    }

    public function rolePermissions(): Collection
    {
        // Spatie already has: $this->getPermissionsViaRoles()
        return $this->getPermissionsViaRoles();
    }

    /**
     * Retrieves the enabled permissions for the model, including both role-based and global permissions.
     *
     * This method constructs a relationship query to fetch permissions associated with the model
     * applied to the model directly and combines them with global permissions that are not restricted. The permissions are filtered
     * based on the current timestamp and guard name.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     *         A BelongsToMany relationship containing the enabled permissions.
     */

    public function enabled_permissions(): BelongsToMany
    {
        $permissionModel = config('permission.models.permission');
        $pivotTable = config('permission.table_names.model_has_permissions');
        $morphKey = config('permission.column_names.model_morph_key');
        $permKey = app(PermissionRegistrar::class)->pivotPermission;
        $relation = $this->morphToMany(
            $permissionModel,
            'model',
            $pivotTable,
            $morphKey,
            $permKey
        );
        $pivotHelper = app(config('permission.pivot_models.model_has_permissions'));
        $pivotHelper::applyActiveToRelation($relation, now());
        if (app(PermissionRegistrar::class)->teams) {
            $teamsKey = app(PermissionRegistrar::class)->teamsKey;
            $relation->withPivot($teamsKey)
                ->wherePivot($teamsKey, getPermissionsTeamId());
        }
        return $relation;
    }


    /**
     * Return all the permissions the model has via roles.
     */
    public function getEnabledPermissionsViaRoles(): Collection
    {
        if (is_a($this, Role::class) || is_a($this, Permission::class)) {
            return collect();
        }

        return $this->loadMissing('roles', 'roles.enabled_permissions')
            ->roles->flatMap(fn($role) => $role->enabled_permissions)
            ->sort()->values();
    }

    /**
     * Effective permissions = direct ∪ via roles (unique by id).
     */
    public function effectivePermissions(?string $guard = null): Collection
    {
        $direct = $this->enabled_permissions()->get();
        $via = $this->getEnabledPermissionsViaRoles();

        $all = $direct->concat($via);
        if ($guard) {
            $all = $all->where('guard_name', $guard);
        }
        return $all->unique('id')->values();
    }

    /**
     * Filters over effective permissions (guard/module/entity).
     */
    public function permissionsFiltered(?string $guard = null, ?array $modules = null, ?array $entities = null): Collection
    {
        return $this->effectivePermissions($guard)->filter(function ($perm) use ($modules, $entities) {
            if ($modules && count($modules) > 0) {
                if (!in_array($perm->module ?? null, $modules, true)) return false;
            }
            if ($entities && count($entities) > 0) {
                $entity = $perm->model ?? null;
                $ok = false;
                foreach ($entities as $raw) {
                    $needle = $raw;
                    if (str_contains($raw, '.')) {
                        $needle = substr($raw, strpos($raw, '.') + 1);
                    }
                    if ($entity && strcasecmp($entity, $needle) === 0) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) return false;
            }
            return true;
        })->values();
    }
}
