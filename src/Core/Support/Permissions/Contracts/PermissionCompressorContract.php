<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions\Contracts;

use Illuminate\Support\Collection;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionCompressedResult;

interface PermissionCompressorContract
{
    public function compress(
        Collection $permissions,
        Collection $allSystemPerms,
        array $options = []
    ): PermissionCompressedResult;
}
