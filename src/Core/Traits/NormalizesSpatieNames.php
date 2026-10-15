<?php

namespace Ronu\RestGenericClass\Core\Traits;

use BackedEnum;

use function Illuminate\Support\enum_value;

/**
 * Shared name normalization for the Spatie permission/role models.
 *
 * Spatie 7+ declares findByName()/findOrCreate() with BackedEnum|string, while
 * Spatie 6 declares string. Accepting BackedEnum|string satisfies both contracts
 * (a wider parameter is always compatible). The value is cast to string because
 * the name column is a string: an int-backed enum must not reach the query as an
 * integer (PostgreSQL rejects varchar = integer) nor be confused with an id.
 */
trait NormalizesSpatieNames
{
    protected static function normalizeName(BackedEnum|string $name): string
    {
        return (string) enum_value($name);
    }

    protected static function normalizeNameAttribute(array $attributes): array
    {
        if (array_key_exists('name', $attributes) && $attributes['name'] instanceof BackedEnum) {
            $attributes['name'] = static::normalizeName($attributes['name']);
        }

        return $attributes;
    }
}
