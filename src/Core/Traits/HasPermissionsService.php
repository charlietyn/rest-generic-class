<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use MongoDB\Laravel\Eloquent\Model;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\PermissionCompressorContract;
use Ronu\RestGenericClass\Core\Support\Permissions\RoleInputResolver;
use Ronu\RestGenericClass\Core\Support\Permissions\RolePermissionAssignmentService;
use Ronu\RestGenericClass\Core\Support\Permissions\RoutePermissionRefresher;
use Ronu\RestGenericClass\Core\Support\Permissions\TargetPermissionResolver;
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
        $assignment = app(RolePermissionAssignmentService::class);
        $mode = $assignment->resolveMode($options);

        if (empty($roleInputs)) {
            throw new \InvalidArgumentException('You must provide at least one role.');
        }
        $roles = app(RoleInputResolver::class)->resolve($roleInputs, $by, $guard);
        if ($roles->isEmpty()) {
            throw new NotFoundResourceException('No roles found for the given identifiers and guard.');
        }
        // Resolve target permissions (multi-source).
        [$resolvedPerms, $createdNames, $usedDefaultAll] = app(TargetPermissionResolver::class)->resolve(
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
                $assignment->applyToRole($role, $mode, $resolvedPerms);
            }

            $perRole[] = [
                'role' => $roleName,
                'guard' => $guard,
                'mode' => $mode,
                'rows' => $assignment->rows($resolvedPerms, $mode),
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
        $assignment = app(RolePermissionAssignmentService::class);
        $mode = $assignment->resolveMode($options);

        if (empty($userInputs)) {
            throw new \InvalidArgumentException('You must provide at least one user identifier.');
        }

        // Resolve users
        $users = $this->findUsers($User, $by, $userInputs);
        if ($users->isEmpty()) {
            throw new \RuntimeException('No users found for the given identifiers.');
        }

        // Resolve target permissions
        [$resolvedPerms, $createdNames, $usedDefaultAll] = app(TargetPermissionResolver::class)->resolve(
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
                $assignment->applyToUser($user, $mode, $resolvedPerms, $pivot);
            }

            $perUser[] = [
                'user_label' => $this->formatUserLabel($user, $by),
                'guard' => $guard,
                'mode' => $mode,
                'rows' => $assignment->rows($resolvedPerms, $mode),
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
     * Retrieves permissions for roles using wildcard compression.
     */
    public function getPermissionsByRolesCompressed(
        array $roleIdsOrNames,
        string $by = 'id',
        ?string $guard = null,
        ?array $modules = null,
        ?array $entities = null,
        array $compressOptions = []
    ): array {
        $raw = $this->getPermissionsByRoles($roleIdsOrNames, $by, $guard, $modules, $entities);
        $allSystemPerms = $this->getAllSystemPermissionsForCompression($guard, $modules, $entities);
        $compressor = app(PermissionCompressorContract::class);

        return array_map(function (array $roleData) use ($allSystemPerms, $compressor, $compressOptions) {
            $compressed = $compressor
                ->compress(collect($roleData['permissions'] ?? []), $allSystemPerms, $compressOptions)
                ->toArray();

            unset($roleData['permissions'], $roleData['count']);

            return array_merge($roleData, $compressed);
        }, $raw);
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
     * Retrieves permissions for users using wildcard compression.
     */
    public function getPermissionsByUsersCompressed(
        array $userSearchValues,
        $userModelClass,
        string $by = 'id',
        ?string $guard = null,
        ?array $modules = null,
        ?array $entities = null,
        array $compressOptions = []
    ): array {
        $raw = $this->getPermissionsByUsers($userSearchValues, $userModelClass, $by, $guard, $modules, $entities);
        $allSystemPerms = $this->getAllSystemPermissionsForCompression($guard, $modules, $entities);
        $compressor = app(PermissionCompressorContract::class);

        return array_map(function (array $userData) use ($allSystemPerms, $compressor, $compressOptions) {
            $compressed = $compressor
                ->compress(collect($userData['permissions'] ?? []), $allSystemPerms, $compressOptions)
                ->toArray();

            unset($userData['permissions'], $userData['count']);

            return array_merge($userData, $compressed);
        }, $raw);
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

    private function getAllSystemPermissionsForCompression(?string $guard = null, ?array $modules = null, ?array $entities = null): Collection
    {
        $permissionClass = app(config('permission.models.permission'));

        return $permissionClass::query()
            ->when($guard, fn($q) => $q->where('guard_name', $guard))
            ->when($modules && count($modules) > 0, fn($q) => $q->whereIn('module', $modules))
            ->when($entities && count($entities) > 0, function ($query) use ($entities) {
                $query->where(function (Builder $outer) use ($entities) {
                    foreach ($entities as $raw) {
                        $raw = trim((string)$raw);
                        $module = null;
                        $entity = $raw;

                        if (Str::contains($raw, '.')) {
                            $module = Str::before($raw, '.');
                            $entity = Str::after($raw, '.');
                        }

                        $outer->orWhere(function (Builder $sub) use ($entity, $module) {
                            $sub->whereRaw('LOWER(model) = ?', [strtolower($entity)]);

                            if (!empty($module)) {
                                $sub->whereRaw('LOWER(module) = ?', [strtolower($module)]);
                            }
                        });
                    }
                });
            })
            ->get();
    }

    // ----------------- Helpers (private) -----------------

    public function refreshPermissions($guard, $dry): array
    {
        return app(RoutePermissionRefresher::class, ['generalModule' => $this->generalModule])
            ->refresh($guard, $dry);
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
