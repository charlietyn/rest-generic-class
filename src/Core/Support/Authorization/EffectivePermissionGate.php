<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Support\Authorization;

use WeakMap;

/** Exact permission grants, cached for the current request/lifecycle only. */
final class EffectivePermissionGate
{
    private WeakMap $permissions;

    public function __construct()
    {
        $this->clear();
    }

    public function allows(object $user, string $ability): ?bool
    {
        // Let Spatie and unrelated policies keep their own authorization semantics.
        if (method_exists($user, 'checkPermissionTo') || !method_exists($user, 'effectivePermissions')) {
            return null;
        }

        $guard = config('rest-generic-class.permissions.authorization.gate_guard');
        $key = $guard ?? '';
        $sets = $this->permissions[$user] ?? [];
        if (!array_key_exists($key, $sets)) {
            $sets[$key] = array_fill_keys($user->effectivePermissions($guard)->pluck('name')->all(), true);
            $this->permissions[$user] = $sets;
        }

        return isset($sets[$key][$ability]) ? true : null;
    }

    public function clear(): void
    {
        // Object identity also isolates equal IDs from different models/connections.
        $this->permissions = new WeakMap();
    }
}
