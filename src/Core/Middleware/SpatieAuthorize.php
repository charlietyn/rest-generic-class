<?php
declare(strict_types=1);

namespace 

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;
use Ronu\RestGenericClass\Core\Resolvers\RouteMetaResolver;
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
     * Priority:
     *   1) Route override via ->defaults('authorize', [...])
     *   2) Route name convention
     *   3) Controller@method convention
     *   4) HTTP verb + first URI segment convention
     *
     * @return array{0: string[], 1: 'any'|'all'}
     */
    protected function resolveRequiredPermissions(
        Request $request,
        ?string $routeName,
        ?string $actionMethod,
        array $actionArr
    ): array {
        // 1) Route explicit override
        $override = Arr::get($actionArr, 'defaults.authorize', null);
        if (is_array($override)) {
            $perms = Arr::get($override, 'permissions');
            $mode  = strtolower((string) Arr::get($override, 'mode', 'any')) === 'all' ? 'all' : 'any';
            $list  = $this->normalizePermissions($perms);
            if (!empty($list)) {
                return [$list, $mode];
            }
        }

        // 2) From route name convention
        if ($perm = $this->mapRouteNameToPermission($routeName)) {
            return [[$perm], 'any'];
        }

        // 3) From Controller@method convention
        if ($perm = $this->mapActionToPermission($request)) {
            return [[$perm], 'any'];
        }

        // 4) From HTTP verb + first URI segment (last resort)
        if ($perm = $this->mapHttpVerbToPermission($request)) {
            return [[$perm], 'any'];
        }

        return [[], 'any'];
    }

    /**
     * Normalize permissions to an array of strings.
     * Accepts: string "a|b|c", string "a", array ['a','b'], null.
     */
    protected function normalizePermissions(null|string|array $perms): array
    {
        if (is_null($perms)) return [];
        if (is_string($perms)) {
            // Support pipe-separated "a|b|c"
            $perms = Str::contains($perms, '|') ? explode('|', $perms) : [$perms];
        }
        return array_values(array_filter(array_map('strval', $perms)));
    }

    /**
     * Map from route name suffix to permission name.
     * Example: 'articles.store'  -> 'articles.create'
     *          'articles.index'  -> 'articles.view'
     */
    protected function mapRouteNameToPermission(?string $name): ?string
    {
        if (!$name) return null;

        $map = [
            '.index'   => '.index',
            '.show'    => '.view',
            '.store'   => '.store',
            '.update'  => '.update',
            '.destroy' => '.delete',
        ];

        foreach ($map as $suffix => $permSuffix) {
            if (Str::endsWith($name, $suffix)) {
                return Str::replaceLast($suffix, $permSuffix, $name);
            }
        }

        // Optional: additional overrides from config
        $overrides = (array) config('permission_map.overrides', []);
        return $overrides[$name] ?? null;
    }

    /**
     * Map from Controller@method to permission.
     * Example: ArticleController@store -> 'article.create'
     */
    protected function mapActionToPermission(Request $request): ?string
    {
        $actionName = $request->route()?->getActionName(); // "App\Http\Controllers\X@store"
        $method     = $request->route()?->getActionMethod();
        if (!$actionName || !$method) return null;

        $resource = Str::of(class_basename(Str::before($actionName, '@')))
            ->replace('Controller', '')
            ->lower(); // "articles"

        $verbMap = [
            'index'   => 'view',
            'show'    => 'view',
            'store'   => 'create',
            'update'  => 'update',
            'destroy' => 'delete',
        ];

        $permVerb = $verbMap[$method] ?? null;
        return $permVerb ? Str::of($resource)->singular().'.'.$permVerb : null; // "article.create"
    }

    /**
     * Last-resort mapping: HTTP verb + first URI segment.
     * Example: POST /articles -> 'article.create'
     */
    protected function mapHttpVerbToPermission(Request $request): ?string
    {
        $method = $request->getMethod(); // GET, POST, PUT, PATCH, DELETE
        $first  = Str::of($request->route()?->uri())->explode('/')->first();

        $verbMap = [
            'GET'    => 'view',
            'POST'   => 'create',
            'PUT'    => 'update',
            'PATCH'  => 'update',
            'DELETE' => 'delete',
        ];

        $permVerb = $verbMap[$method] ?? null;
        if ($permVerb && $first) {
            return Str::of($first)->singular().'.'.$permVerb; // "article.create"
        }
        return null;
    }

    /**
     * Evaluate "ANY": user must hold at least one permission.
     * Uses Gate/Spatie under the hood (cache-aware).
     */
    protected function userCanAny($user, array $permissions): bool
    {
        if (!$user) return false;
        foreach ($permissions as $perm) {
            if ($user->can($perm)) return true;
        }
        return false;
    }

    /**
     * Evaluate "ALL": user must hold all permissions.
     * Uses Gate/Spatie under the hood (cache-aware).
     */
    protected function userCanAll($user, array $permissions): bool
    {
        if (!$user) return false;
        foreach ($permissions as $perm) {
            if (!$user->can($perm)) return false;
        }
        return true;
    }
}
