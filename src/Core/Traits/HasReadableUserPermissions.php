<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\PermissionCompressorContract;
use Ronu\RestGenericClass\Core\Support\Permissions\Exceptions\RolesContractViolationException;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionFilter;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionPayloadBuilder;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionUniverseResolver;
use Ronu\RestGenericClass\Core\Support\Permissions\UserPermissionReader;
use Ronu\RestGenericClass\Core\Support\Permissions\UserRolesResolver;
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
     * Resolve the name of the relation/"functionality" that returns this user's roles.
     *
     * Resolution priority (most specific wins):
     *   1. Per-model constant `ROLES_RELATION` (e.g. const ROLES_RELATION = 'array_role';)
     *   2. Global config 'rest-generic-class.permissions.roles_relation'
     *   3. Hardcoded default 'roles' (backward compatible)
     */
    protected function rolesRelationName(): string
    {
        if (defined(static::class . '::ROLES_RELATION') && !empty(static::ROLES_RELATION)) {
            return static::ROLES_RELATION;
        }

        return config('rest-generic-class.permissions.roles_relation', 'roles') ?: 'roles';
    }

    /**
     * Default implementation of the ProvidesRoles contract.
     *
     * Reads the configurable roles relation (see rolesRelationName()), eager-loads it
     * together with each role's enabled_permissions, and normalizes whatever Eloquent
     * returns into a Collection — supporting BOTH cardinalities:
     *   - one-to-many  (BelongsTo/HasOne)      → a single Model wrapped into a Collection
     *   - many-to-many (BelongsToMany/HasMany)  → the Collection as-is
     *
     * A model that needs custom logic (external source, computed roles) can still
     * override provideRoles() — a class method always wins over this trait default.
     */
    public function provideRoles(): Collection
    {
        $relation = $this->rolesRelationName();

        if (!method_exists($this, $relation)) {
            throw RolesContractViolationException::missingRolesRelation(static::class, $relation);
        }

        $this->loadMissing($relation . '.enabled_permissions');

        return $this->normalizeRolesValue($this->getRelationValue($relation));
    }

    /**
     * Normalize the value of a roles relation into a Collection, regardless of cardinality.
     *
     * @param mixed $value The eager-loaded relation value (Collection, Model or null).
     */
    protected function normalizeRolesValue($value): Collection
    {
        if ($value instanceof Collection) {
            return $value->filter()->values();
        }

        if ($value instanceof Model) {
            return collect([$value]);
        }

        return collect();
    }

    /**
     * Return all the permissions the model has via roles.
     *
     * Delegates to UserRolesResolver, which enforces the ProvidesRoles /
     * ProvidesRolePermissions contracts and centralizes the resolution path.
     */
    public function getEnabledPermissionsViaRoles(): Collection
    {
        if (is_a($this, Role::class) || is_a($this, Permission::class)) {
            return collect();
        }

        return app(UserRolesResolver::class)->permissionsViaRoles($this);
    }

    /**
     * Effective permissions = direct ∪ via roles (unique by id).
     */
    public function effectivePermissions(?string $guard = null): Collection
    {
        return app(UserPermissionReader::class)->effectivePermissions($this, $guard);
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
        return (new PermissionPayloadBuilder())->build(
            $request,
            $context,
            fn(?string $guard, ?array $modules, ?array $entities): Collection => $this->permissionsFiltered($guard, $modules, $entities),
            fn(?string $guard, ?array $modules, ?array $entities, array $options): array => $this->effectivePermissionsCompressed(
                $guard,
                $modules,
                $entities,
                $options
            )
        );
    }

    /**
     * Filters over effective permissions (guard/module/entity).
     */
    public function permissionsFiltered(?string $guard = null, ?array $modules = null, ?array $entities = null): Collection
    {
        return (new PermissionFilter())->filter(
            $this->effectivePermissions($guard),
            modules: $modules,
            entities: $entities
        );
    }

    private function permissionCompressionUniverse(?string $guard = null, ?array $modules = null, ?array $entities = null): Collection
    {
        return app(PermissionUniverseResolver::class)->universe($guard, $modules, $entities);
    }

}
