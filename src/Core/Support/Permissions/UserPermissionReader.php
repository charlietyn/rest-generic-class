<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Support\Collection;

/**
 * Computes direct/role permissions, plus globals when the optional Gate is enabled.
 *
 * Extracted from HasReadableUserPermissions so the trait stops coordinating the
 * union of direct and role-based permissions itself. The trait keeps the same
 * public methods as thin facades over this reader (SRP + DIP).
 */
final class UserPermissionReader
{
    /**
     * Effective permissions for the given user, optionally filtered by guard, unique by id.
     *
     * Reads the user's own public surface (the time-filtered direct relation and
     * the role-based resolution), leaving contract enforcement to UserRolesResolver.
     */
    public function effectivePermissions(object $user, ?string $guard = null): Collection
    {
        $direct = $user->enabled_permissions()->get();
        $via = $user->getEnabledPermissionsViaRoles();

        // Opt in with the Gate so existing integrations retain their legacy union.
        if (config('rest-generic-class.permissions.authorization.register_gate', false)) {
            $permissionClass = config('permission.models.permission');
            $query = $permissionClass::query()->notRestricted();
            if ($guard !== null) {
                $query->where('guard_name', $guard);
            }
            $via = $via->concat($query->get());
        }

        return $this->merge($direct, $via, $guard);
    }

    /**
     * Merge direct and role-based permissions, optionally filtered by guard,
     * deduplicated by id.
     *
     * Pure collection logic kept as a seam so the union/guard/dedup rules can be
     * verified without a user model.
     */
    public function merge(Collection $direct, Collection $via, ?string $guard = null): Collection
    {
        $all = $direct->concat($via);

        if ($guard) {
            $all = $all->where('guard_name', $guard);
        }

        return $all->unique('id')->values();
    }
}
