<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Illuminate\Support\Str;

/**
 * The body key that carries a list of records in the bulk endpoints (bulk create, bulk-update,
 * delete-by-id, restore, force-delete).
 *
 * The library read `strtolower(MODEL)` (`appointmenttypes`) while the generated validation rules
 * name `Str::snake(MODEL)` (`appointment_types`): for every entity with a compound name the request
 * either skipped validation or missed its records (KI-BULK-UPDATE-UNVALIDATED). Both are accepted,
 * the historical one first.
 */
final class EntityKey
{
    /** @return string[] */
    public static function candidates(string $model): array
    {
        if ($model === '') {
            return [];
        }

        return array_values(array_unique([strtolower($model), Str::snake($model)]));
    }

    /** The first candidate present in `$body`, or null. */
    public static function find(array $body, string $model): ?string
    {
        foreach (self::candidates($model) as $key) {
            if (array_key_exists($key, $body)) {
                return $key;
            }
        }

        return null;
    }
}
