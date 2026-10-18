<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

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
     *
     * A user model composed with Spatie's HasPermissions keeps Spatie's own API.
     * One that is not — the generated applications deliberately leave it out,
     * because that trait registers Spatie's Gate::before and would bypass the
     * channel authorization — is written through the same morph relation the
     * package READS direct permissions from (HasReadableUserPermissions::
     * enabled_permissions), so what is assigned is what is later resolved.
     */
    public function applyToUser(object $user, string $mode, Collection $perms, array $pivot = []): void
    {
        if (!method_exists($user, 'givePermissionTo')) {
            $this->applyToUserDirectly($user, $mode, $perms, $pivot);
            return;
        }

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
     * ADD/SYNC/REVOKE on model_has_permissions for a model without Spatie's
     * HasPermissions, honouring teams like Spatie does, then flushing Spatie's
     * permission cache.
     */
    private function applyToUserDirectly(Model $user, string $mode, Collection $perms, array $pivot): void
    {
        $registrar = app(PermissionRegistrar::class);
        $relation = $user->morphToMany(
            config('permission.models.permission'),
            'model',
            config('permission.table_names.model_has_permissions'),
            config('permission.column_names.model_morph_key'),
            $registrar->pivotPermission
        );
        if ($registrar->teams) {
            $teamsKey = $registrar->teamsKey;
            $relation->withPivot($teamsKey)->wherePivot($teamsKey, getPermissionsTeamId());
            $pivot[$teamsKey] = getPermissionsTeamId();
        }

        if ($mode === 'REVOKE') {
            $relation->detach($perms->map(fn($perm) => $perm->getKey())->all());
        } elseif ($mode === 'SYNC') {
            $relation->sync($this->pivotMap($perms, $pivot));
        } else { // ADD
            $relation->syncWithoutDetaching($this->pivotMap($perms, $pivot));
        }

        $registrar->forgetCachedPermissions();
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
