<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Support\Authorization;

/**
 * Evaluates whether a user satisfies a set of required permissions under an
 * "any" (OR) or "all" (AND) policy.
 *
 * Extracted from SpatieAuthorize (SRP). Relies on the user's own `can()` (which
 * is Spatie/Gate cache-aware) and performs no database access. A null user
 * always fails, preserving the middleware's deny-by-default posture.
 */
final class PermissionDecisionEvaluator
{
    /**
     * @param string[] $permissions
     * @param 'any'|'all' $mode
     */
    public function decide(?object $user, array $permissions, string $mode = 'any'): bool
    {
        return strtolower($mode) === 'all'
            ? $this->canAll($user, $permissions)
            : $this->canAny($user, $permissions);
    }

    /**
     * ANY: the user must hold at least one of the permissions.
     */
    public function canAny(?object $user, array $permissions): bool
    {
        if (!$user) return false;
        foreach ($permissions as $perm) {
            if ($user->can($perm)) return true;
        }
        return false;
    }

    /**
     * ALL: the user must hold every permission.
     */
    public function canAll(?object $user, array $permissions): bool
    {
        if (!$user) return false;
        foreach ($permissions as $perm) {
            if (!$user->can($perm)) return false;
        }
        return true;
    }
}
