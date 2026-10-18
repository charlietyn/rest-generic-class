<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Authorization;

use LogicException;

/**
 * The configuration asks the core to authorize eager loads, but no permission integration
 * provides an AuthorizationContext. Raised instead of silently leaving relations open.
 */
final class MissingAuthorizationContextException extends LogicException
{
    public static function forEagerRelations(): self
    {
        return new self(
            'rest-generic-class: authorization.eager_relations=require_read needs an AuthorizationContext, '
            . 'but none is bound (install and register a permission integration such as ronu/rest-permissions, '
            . 'or set eager_relations=off).'
        );
    }
}
