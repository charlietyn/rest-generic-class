<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Authorization;

/**
 * What a permission integration granted for the current request.
 *
 * - guard:    authentication guard the decision was made with (null: the default guard).
 * - action:   the granted action, e.g. "admin.crm.clients.index".
 * - resource: the action without its last segment, e.g. "admin.crm.clients"; relation
 *             abilities are built under it. Null when it cannot be derived: relation
 *             checks then deny (fail closed).
 */
final class AuthorizationScope
{
    public function __construct(
        public readonly ?string $guard,
        public readonly ?string $action,
        public readonly ?string $resource,
    ) {
    }

    /** @return array{guard: ?string, action: ?string, resource: ?string} */
    public function toArray(): array
    {
        return ['guard' => $this->guard, 'action' => $this->action, 'resource' => $this->resource];
    }
}
