<?php
declare(strict_types=1);
namespace Ronu\RestGenericClass\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Nwidart\Modules\Facades\Module;
use Ronu\RestGenericClass\Core\Resolvers\RouteMetaResolver;
use Ronu\RestGenericClass\Core\Support\Authorization\PermissionDecisionEvaluator;
use Ronu\RestGenericClass\Core\Support\Authorization\RequiredPermissionResolver;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * AutoAuthorize Middleware
 *
 * Purpose:
 *   Centralize authorization by deriving required permission(s) from:
 *     1) Route action overrides (explicit)
 *     2) Route name conventions (resource.verb -> resource.permission)
 *     3) Controller@method conventions
 *     4) HTTP verb + URI conventions (last resort)
 *
 * Key ideas:
 *   - Uses Spatie's in-memory cache via PermissionRegistrar (NO DB hits here).
 *   - Supports guard-awareness and Teams/Tenants (if set before this middleware).
 *   - Supports multiple permissions with "any" (OR) or "all" (AND) logic.
 *
 * How to override on a specific route (in routes/web.php or routes/api.php):
 *   Route::post('/articles', [C::class, 'store'])
 *     ->name('articles.store')
 *     ->defaults('authorize', [
 *          'permissions' => ['articles.create', 'articles.publish'], // or string 'articles.create'
 *          'mode'        => 'all', // 'any' | 'all'  (default: 'any')
 *          'guard'       => 'api', // optional; falls back to middleware param or app default
 *     ]);
 *
 * Guard selection priority (highest to lowest):
 *   1) Route override 'guard'
 *   2) Middleware parameter: auto.authorize:api
 *   3) config('auth.defaults.guard')
 *
 * Strict mode (config/permission_map.php):
 *   return [
 *     'strict' => true, // if true, abort when a required permission doesn't exist in cache (prevents typos)
 *   ];
 *
 * Notes for Teams/Tenants:
 *   Ensure a previous middleware sets the current TeamId:
 *     app(PermissionRegistrar::class)->setPermissionsTeamId($teamId);
 *   This ensures the registrar cache key is tenant-aware.
 */
class SpatieAuthorize
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function handle(Request $request, Closure $next, ?string $guardParam = null): Response
    {
        $guard = $guardParam ?: (string) config('auth.defaults.guard');

        $modules = Module::toCollection()->map->getName()->values()->all();
        $resolver = app(RouteMetaResolver::class);
        $meta = $resolver->resolve($request, $guard, $modules, /* $cfg */[]);

        if (!$meta) {
            return $next($request);
        }
        $requiredPermission = $meta->canonicalName;
        $user = auth($guard)->user();
        if (!$user || !$user->can($requiredPermission)) {
            abort(403, config('app.debug') ? "Forbidden: {$requiredPermission}" : 'Forbidden');
        }
        return $next($request);
    }

    /**
     * Resolve the guard using route overrides, middleware param, or app default.
     */
    protected function resolveGuard(array $actionArr, ?string $guardParam): string
    {
        $override = Arr::get($actionArr, 'defaults.authorize.guard');
        return $override
            ?: ($guardParam ?: (string) config('auth.defaults.guard'));
    }

    /**
     * Derive required permissions and evaluation mode ("any"|"all").
     *
     * Thin facade over {@see RequiredPermissionResolver}; kept for backward
     * compatibility with any subclass that relied on this seam.
     *
     * @return array{0: string[], 1: 'any'|'all'}
     */
    protected function resolveRequiredPermissions(
        Request $request,
        ?string $routeName,
        ?string $actionMethod,
        array $actionArr
    ): array {
        return app(RequiredPermissionResolver::class)
            ->resolve($request, $routeName, $actionMethod, $actionArr);
    }

    /**
     * Normalize permissions to an array of strings.
     * Accepts: string "a|b|c", string "a", array ['a','b'], null.
     */
    protected function normalizePermissions(null|string|array $perms): array
    {
        return app(RequiredPermissionResolver::class)->normalizePermissions($perms);
    }

    /**
     * Map from route name suffix to permission name.
     */
    protected function mapRouteNameToPermission(?string $name): ?string
    {
        return app(RequiredPermissionResolver::class)->mapRouteNameToPermission($name);
    }

    /**
     * Map from Controller@method to permission.
     */
    protected function mapActionToPermission(Request $request): ?string
    {
        return app(RequiredPermissionResolver::class)->mapActionToPermission($request);
    }

    /**
     * Last-resort mapping: HTTP verb + first URI segment.
     */
    protected function mapHttpVerbToPermission(Request $request): ?string
    {
        return app(RequiredPermissionResolver::class)->mapHttpVerbToPermission($request);
    }

    /**
     * Evaluate "ANY": user must hold at least one permission.
     */
    protected function userCanAny($user, array $permissions): bool
    {
        return app(PermissionDecisionEvaluator::class)->canAny($user, $permissions);
    }

    /**
     * Evaluate "ALL": user must hold all permissions.
     */
    protected function userCanAll($user, array $permissions): bool
    {
        return app(PermissionDecisionEvaluator::class)->canAll($user, $permissions);
    }
}
