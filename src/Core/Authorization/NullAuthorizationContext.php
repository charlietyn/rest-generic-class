<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Authorization;

use Illuminate\Http\Request;
use Ronu\RestGenericClass\Core\Contracts\Authorization\AuthorizationContext;

/** Default binding: no permission integration is installed, so no request carries a scope. */
final class NullAuthorizationContext implements AuthorizationContext
{
    public function current(?Request $request = null): ?AuthorizationScope
    {
        return null;
    }
}
