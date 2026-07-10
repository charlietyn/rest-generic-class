<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Support\Collection;

/**
 * Applies an ADD/SYNC/REVOKE decision to a role or user and builds the
 * normalized output rows.
 *
 * Extracted from HasPermissionsService so the Spatie assignment/sync calls and
 * the mode unification live in a single collaborator (SRP), leaving the trait
 * methods as thin orchestrators.
 */
final class RolePermissionAssignmentService
{
    /**
     * Resolve the effective mode from the options, honoring the controller-first
     * `mode` key and falling back to the legacy `sync`/`revoke` CLI flags.
     *
     * @return 'ADD'|'SYNC'|'REVOKE'
     * @throws \InvalidArgumentException
     */
    public function resolveMode(array $options): string
    {
        $modeOpt = strtoupper((string)($options['mode'] ?? ''));

        if (in_array($modeOpt, ['ADD', 'SYNC', 'REVOKE'], true)) {
            return $modeOpt;
        }

        $sync = (bool)($options['sync'] ?? false);
        $revoke = (bool)($options['revoke'] ?? false);

        if ($sync && $revoke) {
            throw new \InvalidArgumentException('Cannot use both sync and revoke simultaneously.');
        }

        return $revoke ? 'REVOKE' : ($sync ? 'SYNC' : 'ADD');
    }

    /**
     * Apply the given mode to a role via Spatie's grant/sync/revoke API.
     */
    public function applyToRole(object $role, string $mode, Collection $perms): void
    {
        if ($mode === 'REVOKE') {
            $role->revokePermissionTo($perms);
        } elseif ($mode === 'SYNC') {
            $role->syncPermissions($perms);
        } else { // ADD
            $role->givePermissionTo($perms);
        }
    }

    /**
     * Apply the given mode to a user, preserving optional pivot attributes on
     * model_has_permissions when provided.
     */
    public function applyToUser(object $user, string $mode, Collection $perms, array $pivot = []): void
    {
        if ($mode === 'REVOKE') {
            $user->revokePermissionTo($perms);
            return;
        }

        if ($mode === 'SYNC') {
            if (!empty($pivot)) {
                $user->permissions()->sync($this->pivotMap($perms, $pivot));
            } else {
                $user->syncPermissions($perms);
            }
            return;
        }

        // ADD
        if (!empty($pivot)) {
            $user->permissions()->syncWithoutDetaching($this->pivotMap($perms, $pivot));
        } else {
            $user->givePermissionTo($perms);
        }
    }

    /**
     * Build the normalized, name-sorted output rows for a set of permissions.
     */
    public function rows(Collection $perms, string $mode): array
    {
        return $perms
            ->sortBy('name')
            ->map(fn($perm) => [
                'permission' => $perm->name,
                'module' => $perm->module ?? '-',
                'guard' => $perm->guard_name,
                'action' => $mode,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int|string, array> permission key => pivot attributes
     */
    private function pivotMap(Collection $perms, array $pivot): array
    {
        $map = [];
        foreach ($perms as $perm) {
            $map[$perm->getKey()] = $pivot;
        }

        return $map;
    }
}
