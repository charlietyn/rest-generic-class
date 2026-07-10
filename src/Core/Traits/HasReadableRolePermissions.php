<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Support\Collection;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionFilter;
use Ronu\RestGenericClass\Core\Support\Permissions\RolePermissionReader;

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
        // eager load recommended outside
        return app(RolePermissionReader::class)->allPermissions($this);
    }

    /**
     * Filter by guard/module/entity.
     */
    public function permissionsFiltered(?string $guard = null, ?array $modules = null, ?array $entities = null): Collection
    {
        return (new PermissionFilter())->filter(
            $this->permissions,
            $guard,
            $modules,
            $entities,
            includeUnrestricted: true
        );
    }
}
