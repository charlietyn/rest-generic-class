<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions\Exceptions;

use RuntimeException;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles;

class RolesContractViolationException extends RuntimeException
{
    public static function userMissingContract(string|object $userOrClass): self
    {
        $class = is_object($userOrClass) ? $userOrClass::class : $userOrClass;
        $contract = ProvidesRoles::class;

        return new self(sprintf(
            "User model [%s] must implement %s. Add 'implements %s' to the class and define "
                . "'public function provideRoles(): \\Illuminate\\Support\\Collection' returning the user's roles.",
            $class,
            $contract,
            class_basename($contract)
        ));
    }

    public static function roleMissingContract(string|object $roleOrClass): self
    {
        $class = is_object($roleOrClass) ? $roleOrClass::class : $roleOrClass;
        $contract = ProvidesRolePermissions::class;

        return new self(sprintf(
            "Role model [%s] must implement %s. Add 'implements %s' to the class. "
                . "The trait HasReadableRolePermissions already provides a default 'provideRolePermissions()'.",
            $class,
            $contract,
            class_basename($contract)
        ));
    }
}
