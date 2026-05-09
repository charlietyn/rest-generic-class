<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Support\Collection;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles;
use Ronu\RestGenericClass\Core\Support\Permissions\Exceptions\RolesContractViolationException;

/**
 * Single entry point to resolve a user's roles and the permissions reachable through them.
 *
 * Centralizes the contract enforcement so traits, controllers and services share the same
 * resolution path (DRY) and depend on abstractions only (DIP).
 */
class UserRolesResolver
{
    public function rolesOf(object $user): Collection
    {
        if (!$user instanceof ProvidesRoles) {
            throw RolesContractViolationException::userMissingContract($user);
        }

        $roles = $user->provideRoles();

        foreach ($roles as $role) {
            if (!$role instanceof ProvidesRolePermissions) {
                throw RolesContractViolationException::roleMissingContract($role);
            }
        }

        return $roles;
    }

    public function permissionsViaRoles(object $user): Collection
    {
        return $this->rolesOf($user)
            ->flatMap(fn(ProvidesRolePermissions $role) => $role->provideRolePermissions())
            ->unique('id')
            ->values();
    }
}
