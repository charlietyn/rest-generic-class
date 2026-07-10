<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Support\Authorization;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Derives the required permission(s) and the evaluation mode ("any"|"all") for a
 * request, from (in priority order): an explicit route override, the route name
 * convention, the Controller@method convention, and finally the HTTP verb + URI.
 *
 * Extracted from SpatieAuthorize (SRP) so permission resolution is decoupled
 * from the user evaluation and the HTTP handling of the middleware. Contains no
 * database access.
 */
final class RequiredPermissionResolver
{
    /**
     * @return array{0: string[], 1: 'any'|'all'}
     */
    public function resolve(
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
    public function normalizePermissions(null|string|array $perms): array
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
     * Example: 'articles.store'  -> 'articles.store'
     *          'articles.show'   -> 'articles.view'
     */
    public function mapRouteNameToPermission(?string $name): ?string
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
    public function mapActionToPermission(Request $request): ?string
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
    public function mapHttpVerbToPermission(Request $request): ?string
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
}
