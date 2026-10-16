<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Contracts\Authorization;

use Illuminate\Http\Request;
use Ronu\RestGenericClass\Core\Authorization\AuthorizationScope;

/**
 * Tells the core which authorization, if any, the current request went through.
 *
 * The core never authorizes routes itself: a permission integration (for example
 * ronu/rest-permissions) binds an implementation that exposes the scope its
 * middleware granted. The default binding (NullAuthorizationContext) reports none.
 */
interface AuthorizationContext
{
    /**
     * The scope granted to $request (the current request when null), or null when the
     * request was not authorized by any integration — then nothing is checked.
     */
    public function current(?Request $request = null): ?AuthorizationScope;
}
