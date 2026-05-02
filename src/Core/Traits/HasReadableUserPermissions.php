<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\PermissionCompressorContract;
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
     * Effective permissions compressed into wildcard presentation strings.
     */
    public function effectivePermissionsCompressed(
        ?string $guard = null,
        ?array $modules = null,
        ?array $entities = null,
        array $compressOptions = []
    ): array {
        $permissions = $this->permissionsFiltered($guard, $modules, $entities);
        $allSystemPerms = $this->permissionCompressionUniverse($guard, $modules, $entities);
        $compressor = app(PermissionCompressorContract::class);

        return $compressor
            ->compress($permissions, $allSystemPerms, $compressOptions)
            ->toArray();
    }

    /**
     * Build the authenticated user's permission payload from request flags.
     */
    public function permissionsPayload(Request $request, $context = null): array
    {
        $guard = $request->query('guard');
        $guard = is_string($guard) && $guard !== '' ? $guard : null;
        $modules = $this->permissionPayloadList($request->query('modules'));
        $entities = $this->permissionPayloadList($request->query('entities'));
        $base = $this->permissionPayloadContext($context);

        if ($guard !== null) {
            $base['guard'] = $guard;
        }

        if (!$request->boolean('compress', false)) {
            $permissions = $this->permissionsFiltered($guard, $modules, $entities);

            return array_merge($base, [
                'count' => $permissions->count(),
                'permissions' => $this->permissionPayloadRows($permissions),
            ]);
        }

        return array_merge($base, $this->effectivePermissionsCompressed(
            $guard,
            $modules,
            $entities,
            [
                'module_wildcard' => true,
                'table_wildcard' => true,
                'global_wildcard' => $request->boolean('compress_global', false),
                'include_expanded' => $request->boolean('expand', false),
            ]
        ));
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

    private function permissionCompressionUniverse(?string $guard = null, ?array $modules = null, ?array $entities = null): Collection
    {
        $permissionClass = app(config('permission.models.permission'));

        return $permissionClass::query()
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
            })
            ->get();
    }

    private function permissionPayloadList($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $values = is_array($value) ? $value : explode(',', (string)$value);
        $values = collect($values)
            ->flatMap(fn($item) => is_array($item) ? $item : explode(',', (string)$item))
            ->map(fn($item) => trim((string)$item))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $values === [] ? null : $values;
    }

    private function permissionPayloadContext($context): array
    {
        if ($context === null) {
            return [];
        }

        return is_array($context) ? $context : ['context' => $context];
    }

    private function permissionPayloadRows(Collection $permissions): array
    {
        return $permissions
            ->map(fn($permission) => [
                'id' => $permission->id ?? null,
                'name' => $permission->name,
                'module' => $permission->module ?? null,
                'guard' => $permission->guard_name ?? null,
            ])
            ->values()
            ->all();
    }

}
