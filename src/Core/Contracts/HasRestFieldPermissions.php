<?php

namespace Ronu\RestGenericClass\Core\Contracts;

interface HasRestFieldPermissions
{
    /**
     * @return list<string>
     */
    public function getDeniedFieldsForUser(mixed $user): array;
}
