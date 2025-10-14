<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Resolvers;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;


final class RouteMeta
{
    public function __construct(
        public readonly ?string $routeName,     // e.g. mod_security.role.index
        public readonly string  $uri,           // e.g. api/mod_security/roles/{id}
        public readonly array   $verbs,         // e.g. ['GET','HEAD']
        public readonly ?string $controller,    // e.g. Modules\...\RolesController
        public readonly ?string $method,        // e.g. index|store|update|destroy
        public readonly string  $module,        // e.g. mod_security | --site--
        public readonly string  $model,         // e.g. role
        public readonly string  $action,        // e.g. view|create|update|delete (o index/show si prefieres)
        public readonly string  $canonicalName, // e.g. mod_security.role.view
        public readonly string  $controllerAction // e.g. RolesController@index
    )
    {
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
     * @param string $guard Guard prefix in URI (e.g. 'api')
     * @param string[] $modules Allowed module names (e.g. Module::toCollection()->map->getName()->all())
     * @param array $cfg Optional filters config (same shape you already use)
     */
    public function resolve(Request $request, string $guard, array $modules = [], array $cfg = []): ?RouteMeta
    {
        $route = $request->route();
        if (!$route) {
            return null;
        }

        $prefix = (string)$route->getPrefix();
        if (!Str::startsWith($prefix, $guard)) {
            // Guard-based filtering: mimic your generator behavior
            return null;
        }

        $uri = $route->uri();                 // e.g. 'api/mod_security/roles/{role}'
        $name = $route->getName();             // e.g. 'mod_security.role.index'
        $verbs = $route->methods();             // e.g. ['GET','HEAD']
        $actionArr = $route->getAction();           // array
        $mw = $route->gatherMiddleware();    // array

        // Exclude UI resource pages if requested
        if ($name && $this->isExcludedByAction($name)) {
            return null;
        }

        if (!$this->passesFilters($uri, $name, $mw, $cfg)) {
            return null;
        }

        [$controllerClass, $methodName] = $this->extractControllerAndMethod($actionArr);

        $model = $this->inferModel($controllerClass, $name, $uri, $cfg)
            ?: $this->firstUriSegment($uri)
                ?: 'misc';

        $type = $this->inferAction($methodName, $verbs, $name, $cfg)
            ?: 'read'; // fallback (optionally return null to skip)

        // Determine module by URI after guard segment, matching known module names
        $module = $this->resolveModuleFromUri($uri, $guard, $modules) ?: $this->defaultModule;

        // Canonical, hyphenated (like your seeder)
        $canonical = $this->toCanonicalName($module, $model, $type);

        $controllerAction = $controllerClass
            ? (class_basename($controllerClass) . '@' . ($methodName ?? '-'))
            : '-';

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
            controllerAction: $controllerAction
        );
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
        // Implement your own filters here (whitelists/blacklists by uri/name/middleware)
        // For now just return true to keep parity with your sample.
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

        // 2) From route name: mod_security.role.index => role
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

    /** First meaningful segment after guard (e.g. api/mod_security/roles/... => roles) */
    protected function firstUriSegment(string $uri): ?string
    {
        $parts = array_values(array_filter(explode('/', $uri)));
        return $parts[1] ?? $parts[0] ?? null; // be defensive
    }

    /** Determine module from uri after guard, matching known module list */
    protected function resolveModuleFromUri(string $uri, string $guard, array $modules): ?string
    {
        $parts = array_values(array_filter(explode('/', $uri))); // ['api','mod_security','roles',...]
        if (count($parts) > 2 && ($parts[0] === $guard)) {
            $candidate = $parts[1];
            if (in_array($candidate, $modules, true)) {
                return $candidate;
            }
        }
        return null;
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
        $prefix = (string)$route->getPrefix();
        if (!Str::startsWith($prefix, $guard)) {
            return null;
        }

        $uri = $route->uri();
        $name = $route->getName();
        $verbs = $route->methods();
        $actionArr = $route->getAction();
        $mw = $route->gatherMiddleware();

        if ($name && $this->isExcludedByAction($name)) {
            return null;
        }

        if (!$this->passesFilters($uri, $name, $mw, $cfg)) {
            return null;
        }

        [$controllerClass, $methodName] = $this->extractControllerAndMethod($actionArr);

        $model = $this->inferModel($controllerClass, $name, $uri, $cfg)
            ?: $this->firstUriSegment($uri)
                ?: 'misc';

        $type = $this->inferAction($methodName, $verbs, $name, $cfg)
            ?: 'read';

        $module = $this->resolveModuleFromUri($uri, $guard, $modules) ?: $this->defaultModule;

        $canonical = $this->toCanonicalName($module, $model, $type);

        $controllerAction = $controllerClass
            ? class_basename($controllerClass) . '@' . ($methodName ?? '-')
            : '-';

        return $controllerAction!='-'?new RouteMeta(
            routeName: $name,
            uri: $uri,
            verbs: $verbs,
            controller: $controllerClass,
            method: $methodName,
            module: $module,
            model: $model,
            action: $type,
            canonicalName: $canonical,
            controllerAction: $controllerAction
        ):null;
    }
}
