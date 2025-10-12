<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use MongoDB\Laravel\Eloquent\Model;
use Nwidart\Modules\Facades\Module;
use Symfony\Component\Translation\Exception\NotFoundResourceException;

/**
 * HasPermissionsService
 *
 * Orchestrates ADD/SYNC/REVOKE of permissions for roles.
 * Entry points (Command/Controller) provide inputs; this class performs the action and returns a normalized result.
 */
trait HasPermissionsService
{

    private ?string $generalModule = "--site--";
    private mixed $permissionClass;
    private mixed $roleClass;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the action for a set of roles.
     *
     * @param string[] $roleInputs Role names (non-empty).
     * @param array $options {
     *   guard?: string = 'api',
     *   mode?: 'ADD'|'SYNC'|'REVOKE',        // Preferred; overrides sync/revoke flags
     *   sync?: bool,                          // Legacy flags for CLI
     *   revoke?: bool,
     *   dry_run?: bool = false,
     *   perms?: string[]|null,
     *   prefix?: string|null,
     *   from?: string|null,                   // JSON/YAML file path
     *   modules?: string[]|null,
     *   entities?: string[]|null,             // 'entity' or 'module.entity'
     * }
     *
     * @return array{
     *   summary: array{guard:string,mode:string,perms_count:int,created_count:int,used_default_all:bool},
     *   per_role: array<int, array{role:string,guard:string,mode:string,rows:array<int,array{permission:string,module:string,guard:string,action:string}>}>
     * }
     * @throws \Throwable
     */
    public function assignPermissionToRoles(array $roleInputs, array $options): array
    {
        $guard = (string)($options['guard'] ?? 'api');
        $dryRun = (bool)($options['dry_run'] ?? false);
        $by = strtolower((string)($options['by'] ?? 'name'));

        // Mode unification (controller-first); fallback to CLI flags for backward compatibility.
        $modeOpt = strtoupper((string)($options['mode'] ?? ''));
        $sync = (bool)($options['sync'] ?? false);
        $revoke = (bool)($options['revoke'] ?? false);

        if (in_array($modeOpt, ['ADD', 'SYNC', 'REVOKE'], true)) {
            $mode = $modeOpt;
            $sync = $mode === 'SYNC';
            $revoke = $mode === 'REVOKE';
        } else {
            if ($sync && $revoke) {
                throw new \InvalidArgumentException('Cannot use both sync and revoke simultaneously.');
            }
            $mode = $revoke ? 'REVOKE' : ($sync ? 'SYNC' : 'ADD');
        }

        if (empty($roleInputs)) {
            throw new \InvalidArgumentException('You must provide at least one role.');
        }
        $roles = $this->resolveRoles($roleInputs, $by, $guard);
        if ($roles->isEmpty()) {
            throw new NotFoundResourceException('No roles found for the given identifiers and guard.');
        }
        // Resolve target permissions (multi-source).
        [$resolvedPerms, $createdNames, $usedDefaultAll] = $this->resolveTargetPermissions(
            guard: $guard,
            perms: $options['perms'] ?? null,
            prefix: $options['prefix'] ?? null,
            from: $options['from'] ?? null,
            modules: $options['modules'] ?? null,
            entities: $options['entities'] ?? null,
        );

        // Cache: ensure fresh state (same behavior for CLI/HTTP).
        if (!$dryRun) {
            Artisan::call('cache:forget', ['key' => 'spatie.permission.cache']);
        }

        $perRole = [];

        foreach ($roles as $role) {
            $roleName = $role->name;
            if (!$dryRun) {
                if ($mode === 'REVOKE') {
                    $role->revokePermissionTo($resolvedPerms);
                } elseif ($mode === 'SYNC') {
                    $role->syncPermissions($resolvedPerms);
                } else { // ADD
                    $role->givePermissionTo($resolvedPerms);
                }
            }

            // Rows for painting/JSON
            $rows = $resolvedPerms
                ->sortBy('name')
                ->map(function ($perm) use ($mode) {
                    return [
                        'permission' => $perm->name,
                        'module' => $perm->module ?? '-',
                        'guard' => $perm->guard_name,
                        'action' => $mode,
                    ];
                })
                ->values()
                ->all();

            $perRole[] = [
                'role' => $roleName,
                'guard' => $guard,
                'mode' => $mode,
                'rows' => $rows,
            ];
        }

        return [
            'summary' => [
                'guard' => $guard,
                'mode' => $mode,
                'perms_count' => $resolvedPerms->count(),
                'created_count' => count($createdNames),
                'used_default_all' => $usedDefaultAll,
            ],
            'per_role' => $perRole,
        ];
    }

    /**
     *
     * Single source of truth to ADD/SYNC/REVOKE permissions for users,
     * including optional pivot attributes on model_has_permissions.
     *
     * Options:
     * - guard?: string = 'api'
     * - mode?: 'ADD'|'SYNC'|'REVOKE' (overrides sync/revoke)
     * - sync?: bool
     * - revoke?: bool
     * - dry_run?: bool = false
     * - by?: 'id'|'email'|'name' = 'id'
     * - perms?: string[]|null
     * - prefix?: string|null
     * - from?: string|null (JSON/YAML flat list)
     * - modules?: string[]|null
     * - entities?: string[]|null (either 'entity' or 'module.entity')
     * - pivot?: array<string,mixed> (extra pivot attributes to write with attach/sync)
     */
    /**
     * Execute permission assignment for a set of users.
     *
     * @param array $userInputs // identifiers (ids, emails, or names) according to 'by'
     * @param array $options
     * @return array{
     *   summary: array{guard:string,mode:string,perms_count:int,created_count:int,used_default_all:bool},
     *   per_user: array<int, array{user_label:string,guard:string,mode:string,rows:array<int,array{permission:string,module:string,guard:string,action:string}>}>
     * }
     */

    public function assignPermissionToUsers(array $userInputs, array $options): array
    {
        $userModelClass = config('auth.providers.users.model'); // e.g. App\Models\User
        /** @var \Illuminate\Database\Eloquent\Model $User */
        $User = app($userModelClass);

        $guard = (string)($options['guard'] ?? 'api');
        $dryRun = (bool)($options['dry_run'] ?? false);
        $by = strtolower((string)($options['by'] ?? 'id'));
        $pivot = (array)($options['pivot'] ?? []);

        // Mode unification (controller-first), fallback to flags
        $modeOpt = strtoupper((string)($options['mode'] ?? ''));
        $sync = (bool)($options['sync'] ?? false);
        $revoke = (bool)($options['revoke'] ?? false);

        if (in_array($modeOpt, ['ADD', 'SYNC', 'REVOKE'], true)) {
            $mode = $modeOpt;
            $sync = $mode === 'SYNC';
            $revoke = $mode === 'REVOKE';
        } else {
            if ($sync && $revoke) {
                throw new \InvalidArgumentException('Cannot use both sync and revoke simultaneously.');
            }
            $mode = $revoke ? 'REVOKE' : ($sync ? 'SYNC' : 'ADD');
        }

        if (empty($userInputs)) {
            throw new \InvalidArgumentException('You must provide at least one user identifier.');
        }

        // Resolve users
        $users = $this->findUsers($User, $by, $userInputs);
        if ($users->isEmpty()) {
            throw new \RuntimeException('No users found for the given identifiers.');
        }

        // Resolve target permissions
        [$resolvedPerms, $createdNames, $usedDefaultAll] = $this->resolveTargetPermissions(
            guard: $guard,
            perms: $options['perms'] ?? null,
            prefix: $options['prefix'] ?? null,
            from: $options['from'] ?? null,
            modules: $options['modules'] ?? null,
            entities: $options['entities'] ?? null,
        );

        // Flush Spatie cache once
        if (!$dryRun) {
            Artisan::call('cache:forget', ['key' => 'spatie.permission.cache']);
        }

        $perUser = [];

        foreach ($users as $user) {
            // Apply changes
            if (!$dryRun) {
                if ($mode === 'REVOKE') {
                    $user->revokePermissionTo($resolvedPerms);
                } elseif ($mode === 'SYNC') {
                    if (!empty($pivot)) {
                        $map = [];
                        foreach ($resolvedPerms as $perm) {
                            $map[$perm->getKey()] = $pivot;
                        }
                        $user->permissions()->sync($map); // keep pivot
                    } else {
                        $user->syncPermissions($resolvedPerms);
                    }
                } else { // ADD
                    if (!empty($pivot)) {
                        $payload = [];
                        foreach ($resolvedPerms as $perm) {
                            $payload[$perm->getKey()] = $pivot;
                        }
                        $user->permissions()->syncWithoutDetaching($payload);
                    } else {
                        $user->givePermissionTo($resolvedPerms);
                    }
                }
            }

            // Output rows
            $rows = $resolvedPerms
                ->sortBy('name')
                ->map(fn($perm) => [
                    'permission' => $perm->name,
                    'module' => $perm->module ?? '-',
                    'guard' => $perm->guard_name,
                    'action' => $mode,
                ])
                ->values()
                ->all();

            $perUser[] = [
                'user_label' => $this->formatUserLabel($user, $by),
                'guard' => $guard,
                'mode' => $mode,
                'rows' => $rows,
            ];
        }

        return [
            'summary' => [
                'guard' => $guard,
                'mode' => $mode,
                'perms_count' => $resolvedPerms->count(),
                'created_count' => count($createdNames),
                'used_default_all' => $usedDefaultAll,
            ],
            'per_user' => $perUser,
        ];
    }


    /**
     * Retrieves permissions for a list of roles based on their identifiers.
     *
     * @param array $roleIdsOrNames An array of role identifiers (IDs or names).
     * @param string $by The type of identifier to use for filtering roles ('id' or 'name').
     *                   Defaults to 'id'.
     * @param string|null $guard The guard name to filter permissions (optional).
     * @param array|null $modules A list of module names to filter permissions (optional).
     * @param array|null $entities A list of entities or module.entity pairs to filter permissions (optional).
     *
     * @return array An array containing role information and their associated permissions.
     *               Each entry includes:
     *               - 'role': The name of the role.
     *               - 'guard': The guard name.
     *               - 'count': The number of permissions.
     *               - 'permissions': A list of permissions, each containing:
     *                   - 'id': The permission ID.
     *                   - 'name': The permission name.
     *                   - 'module': The module name.
     *                   - 'guard': The guard name.
     */
    public function getPermissionsByRoles(array $roleIdsOrNames, string $by = 'id', ?string $guard = null, ?array $modules = null, ?array $entities = null): array
    {
        $roleModelClass = app(config('permission.models.role'));
        $permissionClass = app(config('permission.models.permission'));
        $roleHasPermissionClass = app(config('permission.pivot_models.role_has_permissions'));
        /** @var Model $roleModelClass */
        $query = $roleModelClass::query()
            ->with(['permissions' => function ($q) use ($guard, $roleHasPermissionClass) {
                if (!empty($guard)) {
                    $q->where('guard_name', $guard);
                }
                $roleHasPermissionClass::applyActiveToRelation($q, now());
            }]);
        /** @var \Illuminate\Database\Eloquent\Collection $globalPerms */
        $globalPerms = $permissionClass::query()
            ->notRestricted()
            ->when($guard, fn($q) => $q->where('guard_name', $guard))
            ->get();
        $by === 'name'
            ? $query->whereIn('name', $roleIdsOrNames)
            : $query->whereIn('id', $roleIdsOrNames);

        $roles = $query->get();
        foreach ($roles as $role) {
            $merged = $role->permissions
                ->concat($globalPerms)
                ->unique('id')
                ->values();

            // Sobrescribimos la relación en memoria para que aguas abajo vean el conjunto efectivo
            $role->setRelation('permissions', $merged);
        }
        $result = [];
        foreach ($roles as $role) {
            $perms = $guard || $modules || $entities
                ? $role->permissionsFiltered($guard, $modules, $entities)
                : $role->permissions;

            $result[] = [
                'role' => $role->name,
                'guard' => $guard,
                'count' => $perms->count(),
                'permissions' => $perms->map(fn($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'module' => $p->module,
                    'guard' => $p->guard_name,
                ])->values()->all(),
            ];
        }
        return $result;
    }

    /**
     * Retrieves permissions for a list of users based on their identifiers.
     *
     * @param array $userSearchValues An array of user identifiers (IDs, emails, or names).
     * @param string $by The type of identifier to use for filtering users ('id', 'email', or 'name').
     *                   Defaults to 'id'.
     * @param string|null $guard The guard name to filter permissions (optional).
     * @param array|null $modules A list of module names to filter permissions (optional).
     * @param array|null $entities A list of entities or module.entity pairs to filter permissions (optional).
     *
     * @return array An array containing user information and their associated permissions.
     *               Each entry includes:
     *               - 'user': An array with 'id', 'email', and 'name' of the user.
     *               - 'guard': The guard name.
     *               - 'count': The number of permissions.
     *               - 'permissions': A list of permissions, each containing:
     *                   - 'id': The permission ID.
     *                   - 'name': The permission name.
     *                   - 'module': The module name.
     *                   - 'guard': The guard name.
     */
    public function getPermissionsByUsers(array $userSearchValues, $userModelClass, string $by = 'id', ?string $guard = null, ?array $modules = null, ?array $entities = null): array
    {
        /** @var Model $userModelClass */
        $modelHasPermissionClass = app(config('permission.pivot_models.model_has_permissions'));
        $permissionTable = config('permission.table_names.permissions');
        $query = $userModelClass::query()->with(['permissions' => function ($q) use ($guard, $modelHasPermissionClass) {
            if (!empty($guard)) {
                $q->where('guard_name', $guard);
            }
            $modelHasPermissionClass::applyActiveToRelation($q, now());
        }]);
        $query->whereIn($by, $userSearchValues);
        $users = $query->get();
        $result = [];
        foreach ($users as $user) {
            $perms = $user->permissionsFiltered($guard, $modules, $entities);
            $result[] = [
                'user' => [
                    'id' => $user->getKey(),
                    'email' => $user->email,
                    'name' => $user->name,
                ],
                'guard' => $guard,
                'count' => $perms->count(),
                'permissions' => $perms->map(fn($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'module' => $p->module,
                    'guard' => $p->guard_name,
                ])->values()->all(),
            ];
        }
        return $result;
    }

    /**
     * Aggregates a list of permissions by performing either a union or intersection operation.
     *
     * @param array $lists An array of lists, where each list contains permissions to aggregate.
     * @param string $mode The aggregation mode, either 'union' (default) or 'intersection'.
     *                     - 'union': Combines all permissions into a unique set.
     *                     - 'intersection': Finds common permissions across all lists.
     *
     * @return \Illuminate\Support\Collection A collection of aggregated permission names.
     */
    public function aggregate(array $lists, string $mode = 'union'): Collection
    {
        $sets = collect($lists)->map(fn($row) => collect($row['permissions'])->pluck('name')->unique());
        if ($mode === 'intersection') {
            return $sets->reduce(fn($carry, $set) => $carry ? $carry->intersect($set) : $set, null) ?? collect();
        }
        return $sets->flatten()->unique()->values();
    }

    // ----------------- Helpers (private) -----------------

    /**
     * NEW: Resolver roles por 'name' o por 'id' (guard-aware).
     * @throws \Throwable
     */
    private function resolveRoles(array $roleInputs, string $by, string $guard): Collection
    {
        $roleClass = app(config('permission.models.role'));
        $list = collect($roleInputs)
            ->flatMap(fn($r) => Str::of((string)$r)->explode(','))
            ->map(fn($r) => trim((string)$r))
            ->filter()
            ->unique()
            ->values();

        $roles = collect();
        try {
            foreach ($list as $field) {
                try {
                    $role = $roleClass::query()->where($by, $field)->where('guard_name', $guard)->get()->first();
                    $roles->push($role);
                } catch (\Throwable $e) {
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($e->getMessage(), 'column') && str_contains($e->getMessage(), 'does not exist')) {
                $column = explode('"', $e->getMessage())[1] ?? 'unknown';
                $message = "Error querying roles by '{$by}' (column '{$column}' may not exist). ";
            }
            $message = "Error querying roles by '{$by}' " . explode('ERROR', explode('(', $e->getMessage())[0])[1] ?? 'unknown';
            throw new \RuntimeException($message);
        }
        return $roles->filter()->unique('id')->values();
    }

    /** @return array{0:Collection,1:array,2:bool} [resolvedPerms, createdNames, usedDefaultAll] */
    private function resolveTargetPermissions(
        string  $guard,
        ?array  $perms,
        ?string $prefix,
        ?string $from,
        ?array  $modules,
        ?array  $entities,
    ): array
    {
        $names = collect();
        $permissionClass = app(config('permission.models.permission'));
        if (!empty($perms)) {
            $names = $names->merge($this->normalizeList($perms));
        }

        if ($prefix) {
            $prefixed = $permissionClass::query()
                ->where('guard_name', $guard)
                ->where('name', 'like', $prefix . '%')
                ->pluck('name');
            $names = $names->merge($prefixed);
        }

        if ($from) {
            $names = $names->merge($this->loadPermsFromFile($from));
        }

        if (!empty($modules)) {
            $byModule = $this->loadPermsByModule($guard, $this->normalizeList($modules), $permissionClass);
            $names = $names->merge($byModule->pluck('name'));
        }

        if (!empty($entities)) {
            $byEntity = $this->loadPermsByEntity($guard, $this->normalizeList($entities), $permissionClass);
            $names = $names->merge($byEntity->pluck('name'));
        }

        $names = $names->map(fn($n) => trim((string)$n))->filter()->unique()->values();

        $usedDefaultAll = false;
        $createdNames = [];
        $resolved = collect();

        if ($names->isEmpty() && !$prefix && !$from && empty($modules) && empty($entities)) {
            $usedDefaultAll = true;
            $all = $permissionClass::query()->where('guard_name', $guard)->get();
            return [$all, $createdNames, true];
        }

        foreach ($names as $name) {
            $perm = $permissionClass::where('name', $name)->where('guard_name', $guard)->first();
            if ($perm) {
                $resolved->push($perm);
            }
        }

        return [$resolved->unique('id')->values(), $createdNames, $usedDefaultAll];
    }

    private function loadPermsByModule(string $guard, array $modules, $permissionClass): Collection
    {
        return $permissionClass::query()
            ->where('guard_name', $guard)
            ->whereIn('module', $modules)
            ->get();
    }

    private function loadPermsByEntity(string $guard, array $entities, $permissionClass): Collection
    {
        return $permissionClass::query()
            ->where('guard_name', $guard)
            ->where(function (Builder $outer) use ($entities) {
                foreach ($entities as $raw) {
                    $raw = trim((string)$raw);
                    $module = null;
                    $entity = $raw;

                    if (Str::contains($raw, '.')) {
                        $module = Str::before($raw, '.');
                        $entity = Str::after($raw, '.');
                    }

                    $outer->orWhere(function (Builder $sub) use ($entity, $module) {
                        $sub->where('model', 'ilike', $entity);
                        if (!empty($module)) {
                            $sub->where('module', 'ilike', $module);
                        }
                    });
                }
            })
            ->get();
    }

    private function normalizeList(array $values): array
    {
        return collect($values)
            ->flatMap(fn($v) => Str::of((string)$v)->explode(','))
            ->map(fn($v) => trim((string)$v))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function loadPermsFromFile(string $from): array
    {
        $filePath = base_path($from);
        if (!is_file($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contents = file_get_contents($filePath);

        if ($ext === 'json') {
            $list = json_decode($contents, true);
        } elseif (in_array($ext, ['yml', 'yaml'])) {
            if (!function_exists('yaml_parse')) {
                throw new \RuntimeException('YAML extension not available (yaml_parse missing). Use JSON or enable ext/yaml.');
            }
            $list = yaml_parse($contents);
        } else {
            throw new \RuntimeException('Unsupported file extension. Use .json or .yml/.yaml');
        }

        if (!is_array($list)) {
            throw new \RuntimeException('The file does not contain a valid flat list.');
        }

        return $this->normalizeList($list);
    }

    public function refreshPermissions($guard, $dry): array
    {
        Artisan::call('cache:forget spatie.permission.cache');
        $exclude_actions = ['.create', '.edit'];
        $cfg = config('route-permissions', []);
        $permissionClass = app(config('permission.models.permission'));
        $routes = Route::getRoutes();
        $modules = Module::toCollection()->map->getName()->values()->all();
        $rows = [];

        foreach ($routes as $route) {
            $prefix = $route->getPrefix();
            if (!str_starts_with($prefix, $guard))
                continue;
            $uri = $route->uri();                // e.g. 'api/users/{user}'
            $name = $route->getName();            // e.g. 'users.index'
            $array_name = explode('.', $name);
            $resource_action = "." . $array_name[count($array_name) - 1];
            if ($name && in_array($resource_action, $exclude_actions))
                continue;
            $verbs = $route->methods();            // e.g. ['GET', 'HEAD']
            $act = $route->getAction();          // array
            $mw = $route->gatherMiddleware();   // array

            if (!$this->passesFilters($uri, $name, $mw, $cfg)) {
                continue;
            }
            $controller = $act['controller'] ?? null;
            $methodName = null;
            $controllerClass = null;

            if ($controller && str_contains($controller, '@')) {
                [$controllerClass, $methodName] = explode('@', $controller);
            } elseif ($controller && class_exists($controller)) {
                // Invokable
                $controllerClass = $controller;
                $methodName = '__invoke';
            }
            $model = $this->inferModel($controllerClass, $name, $uri, $cfg);
            if (!$model) {
                $model = $this->firstUriSegment($uri) ?: 'misc';
            }
            $type = $this->inferAction($methodName, $verbs, $name, $cfg);
            if (!$type) {
                $type = 'read';
                continue;
            }
            $permissionName = Str::of($model)->snake()->replace('_', '-') . '.' . Str::of($type)->snake()->replace('_', '-');
            $module_name = $this->generalModule;
            if (str_starts_with($uri, $guard . '/')) {
                $uri_explode = explode('/', $uri);
                if (count($uri_explode) > 2 && in_array($uri_explode[1], $modules)) {
                    $module_name = $uri_explode[1];
                }
            }
            $permissionName = $module_name != $this->generalModule ? $module_name . '.' . (string)$permissionName : (string)$permissionName;
            $controllerAction = $controllerClass ? class_basename($controllerClass) . '@' . $methodName : '-';

            $rows[] = [
                'permission' => (string)$permissionName,
                'model' => $model,
                'type' => $type,
                'route' => $uri,
                'methods' => implode('|', $verbs),
                'controller' => $controllerAction
            ];
            if (!$dry) {
                $permission_entity = $permissionClass::query()->where(['name' => $permissionName])->get()->first();
                $attributes = [
                    'name' => $permissionName,
                    'guard_name' => $guard,
                    'module' => $module_name,
                    'route' => $uri,
                    'type' => $type,
                    'model' => $model,
                    'restrict' => false,
                    'action' => $controllerAction,
                ];
                if (!$permission_entity) {
                    $permissionClass::create($attributes);
                } else {
                    $permission_entity->fill($attributes);
                    $permission_entity->save();
                }
            }
        }
        return collect($rows)->unique('permission')->values()->all();
    }

    protected function passesFilters(string $uri, ?string $name, array $middlewares, array $cfg): bool
    {
        // only_prefixes
        $onlyPrefixes = $cfg['only_prefixes'] ?? [];
        if (!empty($onlyPrefixes)) {
            $ok = false;
            foreach ($onlyPrefixes as $p) {
                if (Str::startsWith($uri, $p . '/') || $uri === $p) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) return false;
        }

        // must_have_middlewares
        $must = $cfg['must_have_middlewares'] ?? [];
        foreach ($must as $m) {
            if (!collect($middlewares)->contains(fn($mw) => Str::contains($mw, $m))) {
                return false;
            }
        }

        // exclude (regex)
        $ex = $cfg['exclude'] ?? [];
        foreach ($ex as $regex) {
            if ($name && @preg_match($regex, $name) && preg_match($regex, $name)) return false;
            if (@preg_match($regex, $uri) && preg_match($regex, $uri)) return false;
        }

        return true;
    }

    protected function inferModel(?string $controllerClass, ?string $routeName, string $uri, array $cfg): ?string
    {
        $strategies = $cfg['model_strategies'] ?? ['controller_basename', 'route_name_first', 'uri_first_segment'];
        foreach ($strategies as $s) {
            $model = null;
            if ($s === 'controller_basename' && $controllerClass) {
                $base = class_basename($controllerClass); // UserController
                $model = Str::of($base)->replaceLast('Controller', '')->snake(' ')->trim(); // "user"
                $model = Str::of($model)->replace(' ', '_')->toString();
            } elseif ($s === 'route_name_first' && $routeName) {
                // users.index -> users
                $first = Str::before($routeName, '.');
                $model = $first ?: null;
            } elseif ($s === 'uri_first_segment') {
                $model = $this->firstUriSegment($uri);
            }

            if ($model) {
                $model = Str::slug($model, '_'); // normaliza
                if (($cfg['pluralize_model'] ?? true) && !Str::endsWith($model, ['s'])) {
                    $model = Str::plural($model);
                }
                return $model;
            }
        }
        return null;
    }

    protected function inferAction(?string $methodName, array $verbs, ?string $routeName, array $cfg): ?string
    {
        $mapMethod = $cfg['method_to_action'] ?? [];
        $mapVerb = $cfg['verb_to_action'] ?? [];
        if ($methodName && isset($mapMethod[$methodName])) {
            return $mapMethod[$methodName];
        }
        if ($routeName) {
            $suffix = Str::after($routeName, '.');
            if ($suffix && isset($mapMethod[$suffix])) {
                return $mapMethod[$suffix];
            }
        }
        $verb = collect($verbs)->first(); // normal: GET/POST/PUT/PATCH/DELETE...
        if ($verb && isset($mapVerb[$verb])) {
            return $mapVerb[$verb];
        }

        if (($cfg['fallback_custom_method'] ?? true) && $methodName) {
            return Str::snake($methodName);
        }
        return null;
    }

    protected function firstUriSegment(string $uri): ?string
    {
        $seg = Str::of($uri)->explode('/')->first();
        if (!$seg) return null;
        if (Str::startsWith($seg, '{')) return null; // evita parámetros a nivel raíz
        return Str::slug($seg, '_');
    }

    /** Find users by id|email|name. */
    private function findUsers($User, string $by, array $inputs)
    {
        $users = [];
        try {
            $values = collect($inputs)
                ->flatMap(fn($x) => Str::of((string)$x)->explode(','))
                ->map(fn($x) => trim((string)$x))
                ->filter()
                ->unique()
                ->values();

            $users = $User::query()
                ->where(function (Builder $q) use ($by, $values) {
                    foreach ($values as $value) {
                        $q->orWhere(function (Builder $sub) use ($by, $value) {
                            $sub->where($by, $value);
                        });
                    }
                })
                ->get();
        } catch (\Throwable $e) {
            $column = explode('"', $e->getMessage())[1] ?? 'unknown';
            throw new \RuntimeException("Error querying users by '{$by}' (column '{$column}' may not exist). ");
        }
        return $users;
    }

    /** Human-friendly label for console output. */
    private function formatUserLabel($user, string $by): string
    {
        try {
            if ($by === 'id') {
                return $user->getKey() . ' (' . ($user->email ?? 'no-email') . ')';
            }
            if ($by === 'email') {
                return ($user->email ?? 'no-email') . ' (#' . $user->getKey() . ')';
            }
            // name
            return ($user->name ?? 'no-name') . ' <' . ($user->email ?? 'no-email') . '> (#' . $user->getKey() . ')';
        } catch (\Throwable $e) {
            return (string)$user->getKey();
        }
    }
}
