<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Support\Collection;

/**
 * Reads a role's full permission set: its own permissions merged with the
 * global, non-restricted permissions of the same guard.
 *
 * Extracted from HasReadableRolePermissions so the query against the configured
 * permission model and the merge/dedup rules live in one collaborator (SRP).
 */
final class RolePermissionReader
{
    /**
     * All permissions for the role, merged with non-restricted global permissions.
     *
     * Mirrors the previous trait behaviour: it hydrates the role's `permissions`
     * relation with the merged set as a side effect and returns it.
     */
    public function allPermissions(object $role): Collection
    {
        $merged = $this->merge(
            $role->permissions,
            $this->globalUnrestricted($role->guard_name)
        );

        $role->setRelation('permissions', $merged);

        return $role->permissions;
    }

    /**
     * Merge the role's own permissions with global permissions, deduped by id.
     */
    public function merge(Collection $own, Collection $global): Collection
    {
        return $own->concat($global)->unique('id')->values();
    }

    /**
     * Global, non-restricted permissions for the given guard.
     */
    protected function globalUnrestricted(?string $guard): Collection
    {
        $permissionClass = app(config('permission.models.permission'));

        return $permissionClass::query()
            ->notRestricted()
            ->where('guard_name', $guard)
            ->get();
    }
}
