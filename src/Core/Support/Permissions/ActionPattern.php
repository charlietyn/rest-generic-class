<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

/**
 * Generic notation for granting actions: an action name whose last segment may be `*`.
 *
 *   admin.*                  every action of the admin channel
 *   admin.crm.clients.*      every action of clients, including its relations (admin.crm.clients.pets.read)
 *   *                        every action of the guard
 *
 * `*` matches one or more complete segments: admin.crm.clients.* does not match admin.crm.clients-notes.read.
 * Patterns are expanded against the catalog when granting, so permissions stay concrete action↔role rows.
 */
final class ActionPattern
{
    public static function isPattern(string $value): bool
    {
        return str_contains($value, '*');
    }

    /** `*` alone or as the whole last segment; anything else containing `*` is invalid. */
    public static function isValid(string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }
        if (!str_ends_with($pattern, '.*')) {
            return false;
        }
        $base = substr($pattern, 0, -2);
        return $base !== '' && !str_contains($base, '*') && !in_array('', explode('.', $base), true);
    }

    public static function matches(string $name, string $pattern): bool
    {
        return $pattern === '*' || str_starts_with($name, substr($pattern, 0, -1));
    }

    /**
     * Names of the catalog covered by the pattern.
     *
     * @param iterable<string> $catalog
     * @return string[]
     * @throws \InvalidArgumentException When the pattern is not valid.
     */
    public static function expand(string $pattern, iterable $catalog): array
    {
        if (!self::isValid($pattern)) {
            throw new \InvalidArgumentException(
                "Invalid permission pattern '{$pattern}': only the last segment may be '*' (e.g. admin.crm.clients.*)."
            );
        }
        $matches = [];
        foreach ($catalog as $name) {
            if (self::matches($name, $pattern)) {
                $matches[] = $name;
            }
        }
        return $matches;
    }

    /**
     * A --prefix value bounded by segments: `admin.crm.clients` matches the name itself and
     * `admin.crm.clients.…`, not `admin.crm.clients-notes.…`. A trailing `.` or `.*` is accepted.
     *
     * @param iterable<string> $catalog
     * @return string[]
     */
    public static function expandPrefix(string $prefix, iterable $catalog): array
    {
        $base = rtrim(rtrim(trim($prefix), '*'), '.');
        if ($base === '') {
            return self::expand('*', $catalog);
        }
        $matches = [];
        foreach ($catalog as $name) {
            if ($name === $base || str_starts_with($name, $base . '.')) {
                $matches[] = $name;
            }
        }
        return $matches;
    }
}
