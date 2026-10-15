<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Resolvers;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;


final class RouteMeta
{
    /** @var string[] Absolute alternative actions; holding any one of them is enough (canAny). */
    public readonly array $abilities;

    /** @var string[] Absolute additional actions; all of them are required (canAll). */
    public readonly array $requires;

    /**
     * @param string[] $abilities Defaults to [$canonicalName].
     * @param string[] $requires
     */
    public function __construct(
        public readonly ?string $routeName,     // e.g. {{security_module}}.role.index
        public readonly string  $uri,           // e.g. api/{{security_module}}/roles/{id}
        public readonly array   $verbs,         // e.g. ['GET','HEAD']
        public readonly ?string $controller,    // e.g. Modules\...\RolesController
        public readonly ?string $method,        // e.g. index|store|update|destroy
        public readonly string  $module,        // e.g. {{security_module}} | --site--
        public readonly string  $model,         // e.g. role
        public readonly string  $action,        // e.g. view|create|update|delete (o index/show si prefieres)
        public readonly string  $canonicalName, // e.g. {{security_module}}.role.view; the first ability
        public readonly string  $controllerAction, // e.g. RolesController@index
        public readonly bool    $global = false, // permission_global route action attribute
        array $abilities = [],
        array $requires = []
    )
    {
        $this->abilities = $abilities !== [] ? array_values($abilities) : [$canonicalName];
        $this->requires = array_values($requires);
    }
}

/**
 * RouteMetaResolver
 *
 * - Extracts and normalizes (module, model, action) from the current Route.
 * - Mirrors your seeding logic: infers model from controller/name/uri,
 *   detects module from uri guard segment, supports exclude actions, filters, etc.
 * - Outputs a canonical route "permission-like" name: {module}.{model}.{action}
 */
class RouteMetaResolver
{
    private bool $matchSnakeModule = false;

    public function __construct(
        private readonly string $defaultModule = '--site--',
        private readonly array  $restToPermMap = [
            // You can switch 'index'/'show' to 'view' if you prefer
            'index' => 'index',
            'show' => 'show',
            'store' => 'create',
            'update' => 'update',
            'destroy' => 'delete',
        ],
        private readonly array  $excludeActions = ['.create', '.edit'], // resource UI pages
    )
    {
    }

    /**
     * Resolve Route meta from the Request.
     *
     * @param Request $request
     * @param string $guard Authentication guard; legacy URI prefix (e.g. 'api')
     * @param string[] $modules Allowed module names (e.g. Module::toCollection()->map->getName()->all())
     * @param array $cfg permissions.authorization configuration; also passed to filter hooks
     */
    public function resolve(Request $request, string $guard, array $modules = [], array $cfg = []): ?RouteMeta
    {
        $route = $request->route();
        return $route ? $this->resolveFromRoute($route, $guard, $modules, $cfg) : null;
    }

    /** Match a configured URI prefix on segment boundaries, or use legacy scope. */
    protected function matchedPrefix(Route $route, string $guard, array $cfg): ?string
    {
        $prefixes = $cfg['route_prefixes'] ?? null;
        if ($prefixes === null) {
            return Str::startsWith((string) $route->getPrefix(), $guard) ? $guard : null;
        }

        $uri = trim($route->uri(), '/');
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix, '/');
            if ($prefix !== '' && ($uri === $prefix || Str::startsWith($uri, $prefix . '/'))) {
                return $prefix;
            }
        }
        return null;
    }

    /** Exclude routes like *.create / *.edit when listing resource pages (UI) */
    protected function isExcludedByAction(?string $routeName): bool
    {
        if (!$routeName) return false;
        foreach ($this->excludeActions as $suffix) {
            if (Str::endsWith($routeName, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /** Pass-through filter hook (replicates your passesFilters signature) */
    protected function passesFilters(string $uri, ?string $name, array $mw, array $cfg): bool
    {
        // Extension hook: override for application-specific filtering.
        // No built-in URI/name/middleware filter configuration is interpreted here.
        return true;
    }

    /** Extract controller FQCN and method (supports invokable) */
    protected function extractControllerAndMethod(array $actionArr): array
    {
        $controller = $actionArr['controller'] ?? null;
        $methodName = null;
        $className = null;

        if ($controller && str_contains($controller, '@')) {
            [$className, $methodName] = explode('@', $controller);
        } elseif ($controller && class_exists($controller)) {
            // Invokable controller
            $className = $controller;
            $methodName = '__invoke';
        }
        return [$className, $methodName];
    }

    /** Try to infer the domain model name from Controller, route name or URI */
    protected function inferModel(?string $controllerClass, ?string $routeName, string $uri, array $cfg): ?string
    {
        // 1) From Controller class name: RolesController => roles => role
        if ($controllerClass) {
            $fromCtrl = Str::of(class_basename($controllerClass))
                ->replace('Controller', '')
                ->lower(); // roles
            if ($fromCtrl !== '') {
                return (string)Str::of($fromCtrl); // role
            }
        }

        // 2) From route name: {{security_module}}.role.index => role
        if ($routeName) {
            $parts = explode('.', $routeName);
            if (count($parts) >= 2) {
                return (string)Str::of($parts[count($parts) - 2]);
            }
        }

        // 3) From URI first/second segment after guard, as fallback
        return $this->firstUriSegment($uri);
    }

    /** Map framework/controller verbs to "permission" action */
    protected function inferAction(?string $methodName, array $verbs, ?string $routeName, array $cfg): ?string
    {
        // 1) From controller method (RESTful)
        if ($methodName) {
            $verbMap = $this->restToPermMap; // index->view, show->view, store->create...
            if (isset($verbMap[$methodName])) {
                return $verbMap[$methodName];
            }
            // Custom method => snake as action (e.g. actionValidate => action_validate)
            return (string)Str::of($methodName)->snake();
        }

        // 2) From route name suffix
        if ($routeName) {
            $suffix = (string)Str::afterLast($routeName, '.'); // index|show|store|...
            $verbMap = $this->restToPermMap;
            if (isset($verbMap[$suffix])) {
                return $verbMap[$suffix];
            }
            return (string)Str::of($suffix)->snake();
        }

        // 3) From HTTP verb (last resort)
        $map = ['GET' => 'view', 'HEAD' => 'view', 'POST' => 'create', 'PUT' => 'update', 'PATCH' => 'update', 'DELETE' => 'delete'];
        foreach ($verbs as $v) {
            if (isset($map[$v])) return $map[$v];
        }

        return null;
    }

    /** First meaningful segment after guard (e.g. api/{{security_module}}/roles/... => roles) */
    protected function firstUriSegment(string $uri): ?string
    {
        $parts = array_values(array_filter(explode('/', $uri)));
        return $parts[1] ?? $parts[0] ?? null; // be defensive
    }

    /** Determine module from uri after guard, matching known module list */
    protected function resolveModuleFromUri(string $uri, string $guard, array $modules): ?string
    {
        return $this->matchModuleFromUri($uri, $guard, $modules, $this->matchSnakeModule);
    }

    /** Keep the three-argument extension hook compatible with existing subclasses. */
    private function matchModuleFromUri(string $uri, string $guard, array $modules, bool $matchSnake = false): ?string
    {
        if (!$matchSnake) {
            // Match v2.5.0 exactly, including short URIs and the literal guard segment.
            $parts = array_values(array_filter(explode('/', $uri)));
            if (count($parts) > 2 && $parts[0] === $guard && in_array($parts[1], $modules, true)) {
                return $parts[1];
            }
            return null;
        }

        $prefix = trim($guard, '/');
        $uri = trim($uri, '/');
        if (Str::startsWith($uri, $prefix . '/')) {
            $candidate = explode('/', substr($uri, strlen($prefix) + 1))[0];
            foreach ($modules as $module) {
                if ($candidate === $module || $candidate === Str::snake($module)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function resolveModuleForRoute(string $uri, string $prefix, array $modules, bool $matchSnake): ?string
    {
        // Preserve overridden resolveModuleFromUri() in both modes. Restore the mode
        // even after a nested resolution or an exception in an application override.
        $previous = $this->matchSnakeModule;
        $this->matchSnakeModule = $matchSnake;
        try {
            return $this->resolveModuleFromUri($uri, $prefix, $modules);
        } finally {
            $this->matchSnakeModule = $previous;
        }
    }

    /** Build canonical "permission-like" name with hyphenated segments */
    protected function toCanonicalName(string $module, string $model, string $action): string
    {
        $hyphen = fn(string $s) => Str::of($s)->snake()->replace('_', '-')->toString();

        $model = $hyphen($model);
        $action = $hyphen($action);

        return $module !== $this->defaultModule
            ? "{$module}.{$model}.{$action}"
            : "{$model}.{$action}";
    }

    public function resolveFromRoute(
        Route $route,
        string       $guard,
        array        $modules = [],
        array        $cfg = []
    ): ?RouteMeta
    {
        $prefix = $this->matchedPrefix($route, $guard, $cfg);
        if ($prefix === null) {
            return null;
        }

        $uri = $route->uri();
        $version = preg_replace('/[^A-Za-z0-9]/', '', (string) Arr::get($route->getAction(), 'version', ''));
        $name = $route->getName();
        $routeNaming = ($cfg['naming'] ?? 'derived') === 'route_name';
        if ($routeNaming && ($name === null || $name === '')) {
            return null;
        }
        $verbs = $route->methods();
        $actionArr = $route->getAction();
        $mw = $route->gatherMiddleware();

        if (!$routeNaming && $name && $this->isExcludedByAction($name)) {
            return null;
        }

        if (!$this->passesFilters($uri, $name, $mw, $cfg)) {
            return null;
        }

        [$controllerClass, $methodName] = $this->extractControllerAndMethod($actionArr);

        $model = $this->inferModel($controllerClass, $name, $uri, $cfg)
            ?: $this->firstUriSegment($uri)
                ?: 'misc';

        $type = $routeNaming
            ? (string) Str::afterLast($name, '.')
            : ($this->inferAction($methodName, $verbs, $name, $cfg) ?: 'read');

        $matchSnake = ($cfg['route_prefixes'] ?? null) !== null;
        $module = $this->resolveModuleForRoute($uri, $prefix, $modules, $matchSnake) ?: $this->defaultModule;

        $canonical = $this->toCanonicalName($module, $model, $type);
        $canonical = $routeNaming ? $name : ($version ? $version . '.' . $canonical : $canonical);
        $controllerAction = $controllerClass
            ? class_basename($controllerClass) . '@' . ($methodName ?? '-')
            : '-';

        if (!$routeNaming && $controllerAction === '-') {
            return null;
        }

        $abilities = [];
        $requires = [];
        if ($routeNaming) {
            $abilities = $this->resolveAbilities($name, $actionArr['permission_ability'] ?? null);
            $requires = $this->resolveRequires($name, $actionArr['permission_requires'] ?? null);
            if ($abilities === null || $requires === null) {
                return null; // misconfigured attributes: unresolvable (403 with on_unresolved=deny)
            }
            if ($abilities !== []) {
                $canonical = $abilities[0];
                $type = (string) Str::afterLast($canonical, '.');
            }
        } elseif (isset($actionArr['permission_ability']) || isset($actionArr['permission_requires'])) {
            self::warnIgnoredAttributesOnce();
        }

        return new RouteMeta(
            routeName: $name,
            uri: $uri,
            verbs: $verbs,
            controller: $controllerClass,
            method: $methodName,
            module: $module,
            model: $model,
            action: $type,
            canonicalName: $canonical,
            controllerAction: $controllerAction,
            global: (bool) Arr::get($actionArr, 'permission_global', false),
            abilities: $abilities,
            requires: $requires
        );
    }

    /**
     * permission_ability (string|string[]) replaces the last segment of the route name; a list is a set
     * of alternatives. Returns [] when the attribute is absent and null when it is invalid.
     *
     * @return string[]|null
     */
    protected function resolveAbilities(string $routeName, mixed $attribute): ?array
    {
        if ($attribute === null) {
            return [];
        }
        $values = is_array($attribute) ? array_values($attribute) : [$attribute];
        if ($values === [] || !str_contains($routeName, '.')) {
            return null;
        }
        $base = Str::beforeLast($routeName, '.');
        $abilities = [];
        foreach ($values as $value) {
            if (!is_string($value) || !self::isSegmentPath($value) || str_contains($value, '.')) {
                return null;
            }
            $abilities[] = $base . '.' . $value;
        }
        return array_values(array_unique($abilities));
    }

    /**
     * permission_requires (string[]): `^` removes one trailing segment of the route name before appending
     * the rest (`^^show` on admin.crm.clients.pets.list → admin.crm.clients.show); `=name` is absolute.
     * Returns null when a value is invalid or runs out of segments.
     *
     * @return string[]|null
     */
    protected function resolveRequires(string $routeName, mixed $attribute): ?array
    {
        if ($attribute === null) {
            return [];
        }
        $requires = [];
        foreach (is_array($attribute) ? $attribute : [$attribute] as $value) {
            $resolved = is_string($value) ? self::resolveRelativeAction($routeName, $value) : null;
            if ($resolved === null) {
                return null;
            }
            $requires[] = $resolved;
        }
        return array_values(array_unique($requires));
    }

    /** Apply the permission_requires grammar to one value; null when it is invalid. */
    public static function resolveRelativeAction(string $routeName, string $value): ?string
    {
        if (str_starts_with($value, '=')) {
            $absolute = substr($value, 1);
            return self::isSegmentPath($absolute) ? $absolute : null;
        }
        $carets = strspn($value, '^');
        $suffix = substr($value, $carets);
        if ($carets === 0 || !self::isSegmentPath($suffix)) {
            return null;
        }
        $segments = explode('.', $routeName);
        if ($carets >= count($segments)) {
            return null;
        }
        return implode('.', array_slice($segments, 0, -$carets)) . '.' . $suffix;
    }

    /** Non-empty dot-separated segments without wildcards or whitespace. */
    private static function isSegmentPath(string $value): bool
    {
        return $value !== '' && preg_match('/^[^.\s*^=]+(\.[^.\s*^=]+)*$/', $value) === 1;
    }

    private static bool $warnedIgnoredAttributes = false;

    private static function warnIgnoredAttributesOnce(): void
    {
        if (self::$warnedIgnoredAttributes) {
            return;
        }
        self::$warnedIgnoredAttributes = true;
        if (function_exists('logger')) {
            try {
                logger()->warning('rest-generic-class: permission_ability/permission_requires route attributes '
                    . 'are only applied with permissions.authorization.naming=route_name; they are ignored.');
            } catch (\Throwable) {
                // Logging must never break authorization.
            }
        }
    }
}
