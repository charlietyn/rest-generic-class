<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Authorization;

/**
 * Core authorization settings, read from rest-generic-class.authorization.*.
 *
 * Until v2.8 these lived under rest-generic-class.permissions.authorization.*. A value set
 * in the new block wins; otherwise the legacy key is honoured, then the default.
 */
final class AuthorizationConfig
{
    public const OFF = 'off';
    public const REQUIRE_READ = 'require_read';
    public const ALLOW = 'allow';

    /** off | require_read */
    public static function eagerRelations(): string
    {
        return (string) self::read('eager_relations', self::OFF);
    }

    /** allow | require_read */
    public static function eagerRelationsToOne(): string
    {
        return (string) self::read('eager_relations_to_one', self::ALLOW);
    }

    /** @return list<string> Ability suffixes that grant reading a relation (any of them). */
    public static function relationAbilities(): array
    {
        $abilities = config('rest-generic-class.authorization.relation_abilities');
        $abilities = is_array($abilities) ? array_values(array_filter($abilities, 'is_string')) : [];
        return $abilities !== [] ? $abilities : ['read', 'manage'];
    }

    public static function strictRelations(): bool
    {
        return (bool) config('rest-generic-class.filtering.strict_relations', true);
    }

    private static function read(string $key, string $default): mixed
    {
        return config("rest-generic-class.authorization.{$key}")
            ?? config("rest-generic-class.permissions.authorization.{$key}")
            ?? $default;
    }
}
