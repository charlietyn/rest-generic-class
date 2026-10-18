<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Authorization;

use Illuminate\Container\Container;
use Ronu\RestGenericClass\Core\Contracts\Authorization\AuthorizationContext;
use Ronu\RestGenericClass\Core\Contracts\Authorization\FieldWriteAuthorizer;
use Ronu\RestGenericClass\Core\Contracts\Authorization\RelationAuthorizer;

/**
 * Resolves the authorization contracts from the container, falling back to the core defaults
 * when they are not bound (standalone Eloquent/Capsule usage, or before the provider registers).
 */
final class Authorizers
{
    public static function relations(): RelationAuthorizer
    {
        $container = Container::getInstance();
        if ($container->bound(RelationAuthorizer::class)) {
            return $container->make(RelationAuthorizer::class);
        }
        return new GateRelationAuthorizer(self::context(), new RelationAbilityNamer());
    }

    public static function fields(): FieldWriteAuthorizer
    {
        $container = Container::getInstance();
        return $container->bound(FieldWriteAuthorizer::class)
            ? $container->make(FieldWriteAuthorizer::class)
            : new RoleFieldWriteAuthorizer();
    }

    public static function context(): AuthorizationContext
    {
        $container = Container::getInstance();
        return $container->bound(AuthorizationContext::class)
            ? $container->make(AuthorizationContext::class)
            : new NullAuthorizationContext();
    }
}
