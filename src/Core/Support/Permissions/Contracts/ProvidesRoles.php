<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions\Contracts;

use Illuminate\Support\Collection;

/**
 * Contract a User model MUST implement to integrate with the package's permission engine.
 *
 * The implementer decides how to obtain the roles (Eloquent relation, custom resolver,
 * external source). The only contract is: return a Collection whose items implement
 * ProvidesRolePermissions.
 *
 * Eager loading is the implementer's responsibility. Recommended pattern when roles come
 * from a Spatie-style relation:
 *
 *     public function provideRoles(): Collection {
 *         return $this->load('roles.enabled_permissions')->roles;
 *     }
 */
interface ProvidesRoles
{
    public function provideRoles(): Collection;
}
