<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Support\Str;

/**
 * Normalizes loosely-typed permission/role/module input lists into a clean,
 * comma-split, trimmed, de-duplicated array of non-empty strings.
 *
 * Shared by the permission service collaborators so the "accept arrays or
 * comma strings" contract lives in one place (DRY).
 */
final class PermissionListNormalizer
{
    /**
     * @param array $values Any mix of scalars, comma strings and nested arrays.
     * @return array<int, string>
     */
    public function normalize(array $values): array
    {
        return collect($values)
            ->flatMap(fn($v) => Str::of((string)$v)->explode(','))
            ->map(fn($v) => trim((string)$v))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
