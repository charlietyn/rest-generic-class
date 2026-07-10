<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Support\Collection;

/**
 * Computes a user's effective permissions (direct ∪ via roles).
 *
 * Extracted from HasReadableUserPermissions so the trait stops coordinating the
 * union of direct and role-based permissions itself. The trait keeps the same
 * public methods as thin facades over this reader (SRP + DIP).
 */
final class UserPermissionReader
{
    /**
     * Effective permissions for the given user: direct ∪ via roles, unique by id.
     *
     * Reads the user's own public surface (the time-filtered direct relation and
     * the role-based resolution), leaving contract enforcement to UserRolesResolver.
     */
    public function effectivePermissions(object $user, ?string $guard = null): Collection
    {
        $direct = $user->enabled_permissions()->get();
        $via = $user->getEnabledPermissionsViaRoles();

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
