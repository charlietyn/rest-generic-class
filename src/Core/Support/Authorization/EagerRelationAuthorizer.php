<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Support\Authorization;

use Ronu\RestGenericClass\Core\Authorization\GateRelationAuthorizer;

/**
 * @deprecated since 2.9.0. Resolve Ronu\RestGenericClass\Core\Contracts\Authorization\RelationAuthorizer
 *             instead; the default implementation is Ronu\RestGenericClass\Core\Authorization\GateRelationAuthorizer.
 *             Kept so existing app(EagerRelationAuthorizer::class) calls keep working; same behaviour.
 */
final class EagerRelationAuthorizer extends GateRelationAuthorizer
{
}
