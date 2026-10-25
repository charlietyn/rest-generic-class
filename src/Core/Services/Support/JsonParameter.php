<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Decodes a structured request parameter (`oper`, `attr`, `eq`, `orderby`, `pagination`) that may
 * arrive as a JSON string.
 *
 * A string that is not valid JSON used to be dropped, so a typo in a filter answered the WHOLE
 * table with a 200 (KI-OPER-INVALID-JSON). It is now a 400 with the NestJS libraries' message;
 * `filtering.reject_invalid_json=false` restores the old behaviour (the raw string is returned and
 * every caller ignores it as before). The aggregate path keeps AggregateSpecParser's
 * "<param>: contains invalid JSON." — the same split NestJS has.
 */
final class JsonParameter
{
    /** The decoded value; a non-string is returned as is, a blank string as null. */
    public static function decode(mixed $value, string $param): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if (trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        if (!self::rejects()) {
            return $value;
        }

        throw new HttpException(400, "The {$param} parameter is not valid JSON.");
    }

    /** decode() for a parameter that must be an array: anything else is [] (the historical rule). */
    public static function decodeArray(mixed $value, string $param): array
    {
        $decoded = self::decode($value, $param);

        return is_array($decoded) ? $decoded : [];
    }

    private static function rejects(): bool
    {
        try {
            return (bool) config('rest-generic-class.filtering.reject_invalid_json', true);
        } catch (\Throwable) {
            return true;
        }
    }
}
