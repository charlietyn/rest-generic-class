<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Authorization;

use Illuminate\Database\Eloquent\Model;
use Ronu\RestGenericClass\Core\Contracts\Authorization\FieldWriteAuthorizer;

/**
 * Default field write rules: the model's $fieldsByRole map ([role => [fields]]).
 *
 *  1. no map declared                → [] (no restrictions)
 *  2. $user->is_superuser === true   → [] (unrestricted)
 *  3. denied = every mapped field − the fields of the roles the user holds
 *
 * Roles are checked with $user->hasRole() when the user has it (Spatie's HasRoles provides it);
 * a user without hasRole() holds no role. No permission package is required.
 */
final class RoleFieldWriteAuthorizer implements FieldWriteAuthorizer
{
    public function deniedFields(Model $model, mixed $user): array
    {
        $fieldsByRole = method_exists($model, 'getFieldsByRole') ? $model->getFieldsByRole() : [];
        if ($fieldsByRole === []) {
            return [];
        }
        if ($user->is_superuser ?? false) {
            return [];
        }

        $privileged = array_unique(array_merge(...array_values($fieldsByRole)));
        $allowed = [];
        foreach ($fieldsByRole as $role => $fields) {
            if (is_object($user) && method_exists($user, 'hasRole') && $user->hasRole($role)) {
                $allowed = array_merge($allowed, $fields);
            }
        }
        return array_values(array_diff($privileged, $allowed));
    }
}
