<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions\Contracts;

use Illuminate\Support\Collection;

/**
 * Contract a Role model MUST implement to integrate with the package's permission engine.
 *
 * Returns the enabled permissions of the role (already filtered by activation window,
 * guard, etc., according to the implementer's domain rules).
 *
 * The trait HasReadableRolePermissions provides a sensible default implementation that
 * returns $this->enabled_permissions. Implementers can override to plug a different
 * source (custom relation, computed list, external service).
 */
interface ProvidesRolePermissions
{
    public function provideRolePermissions(): Collection;
}
