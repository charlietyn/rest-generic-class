<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Support\Collection;

/**
 * Resolves role inputs (names or ids) into role models, guard-aware.
 *
 * Extracted from HasPermissionsService so the trait no longer owns the role
 * lookup and its error-message translation (SRP).
 */
final class RoleInputResolver
{
    public function __construct(
        private ?PermissionListNormalizer $normalizer = null
    ) {
        $this->normalizer ??= new PermissionListNormalizer();
    }

    /**
     * Resolve roles by 'name' or 'id' for the given guard.
     *
     * @param string[] $roleInputs
     * @throws \RuntimeException When the identifier column cannot be queried.
     */
    public function resolve(array $roleInputs, string $by, string $guard): Collection
    {
        $roleClass = app(config('permission.models.role'));
        $list = collect($this->normalizer->normalize($roleInputs));

        $roles = collect();
        try {
            foreach ($list as $field) {
                $role = $roleClass::query()
                    ->where($by, $field)
                    ->where('guard_name', $guard)
                    ->get()
                    ->first();
                $roles->push($role);
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($e->getMessage(), 'column') && str_contains($e->getMessage(), 'does not exist')) {
                $column = explode('"', $e->getMessage())[1] ?? 'unknown';
                $message = "Error querying roles by '{$by}' (column '{$column}' may not exist). ";
            }
            $message = "Error querying roles by '{$by}' " . explode('ERROR', explode('(', $e->getMessage())[0])[1] ?? 'unknown';
            throw new \RuntimeException($message);
        }

        return $roles->filter()->unique('id')->values();
    }
}
