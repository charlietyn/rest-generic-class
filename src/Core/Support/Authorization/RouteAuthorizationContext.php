<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Support\Authorization;

use Illuminate\Http\Request;
use Ronu\RestGenericClass\Core\Authorization\AuthorizationScope;
use Ronu\RestGenericClass\Core\Contracts\Authorization\AuthorizationContext;
use Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize;

/**
 * AuthorizationContext of the route permissions: the scope SpatieAuthorize stored on the request
 * (SpatieAuthorize::CONTEXT_ATTRIBUTE) after granting it. Requests it did not authorize have none.
 */
final class RouteAuthorizationContext implements AuthorizationContext
{
    public function current(?Request $request = null): ?AuthorizationScope
    {
        $request ??= app()->bound('request') ? app('request') : null;
        $context = $request?->attributes->get(SpatieAuthorize::CONTEXT_ATTRIBUTE);
        if (!is_array($context)) {
            return null;
        }
        return new AuthorizationScope(
            isset($context['guard']) ? (string) $context['guard'] : null,
            isset($context['action']) ? (string) $context['action'] : null,
            isset($context['resource']) ? (string) $context['resource'] : null,
        );
    }
}
