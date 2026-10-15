<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;
use Ronu\RestGenericClass\Core\Resolvers\RouteMetaResolver;

/**
 * Syncs the action catalog (Spatie's permissions table) from the application's registered routes.
 *
 * Every alternative of a route's abilities is an action: the eight routes of a relation declared with
 * permission_ability produce five actions (read, add, update, delete, manage). Actions are de-duplicated
 * before writing; existing rows are loaded once and only changed rows are written, inside one
 * transaction, in batches, with the query builder (no model events). The permission cache is reset once.
 *
 * Extracted from HasPermissionsService so route-derived permission creation is
 * isolated from role/permission assignment concerns (SRP).
 */
final class RoutePermissionRefresher
{
    private const BATCH = 500;

    private array $report = [];

    public function __construct(
        private string $generalModule = '--site--'
    ) {
    }

    /**
     * Rebuild the actions derived from the route table.
     *
     * @param string $guard Guard name to stamp on created/updated actions.
     * @param bool   $dry   When true, only reports; nothing is written and the cache is kept.
     * @param bool   $prune Delete actions of the guard that no route produces any more, unless a role
     *                      or a model holds a permission on them (those are reported as orphans).
     * @return array<int, array<string, mixed>> One row per action; `route`, `methods` and `controller`
     *                                          list every route that produces it.
     */
    public function refresh($guard, $dry, bool $prune = false): array
    {
        $guard = (string) $guard;
        $cfg = config('rest-generic-class.permissions.authorization', []);
        $explicit = (bool) ($cfg['explicit_globals'] ?? false);
        $markers = $cfg['catalog_middleware'] ?? null;
        $modules = class_exists(Module::class) ? Module::toCollection()->map->getName()->values()->all() : [];
        /** @var RouteMetaResolver $resolver */
        $resolver = app(RouteMetaResolver::class);

        $actions = [];
        $rows = [];
        foreach (Route::getRoutes() as $route) {
            if ($markers !== null && !collect($route->gatherMiddleware())->contains(
                static fn ($middleware) => is_string($middleware) && Str::startsWith($middleware, $markers)
            )) {
                continue;
            }
            $meta = $resolver->resolveFromRoute($route, $guard, $modules, $cfg);
            if (!$meta) {
                continue;
            }
            $restrict = $explicit ? !$meta->global : $meta->module !== $this->generalModule;
            foreach ($meta->abilities as $name) {
                if (!isset($actions[$name])) {
                    // route and action come from the first route that produces the action
                    $actions[$name] = [
                        'name' => $name,
                        'guard_name' => $guard,
                        'module' => $meta->module,
                        'route' => $meta->uri,
                        'type' => (string) Str::afterLast($name, '.'),
                        'model' => $meta->model,
                        'restrict' => $restrict,
                        'action' => $meta->controllerAction,
                    ];
                    $rows[$name] = [
                        'permission' => $name,
                        'model' => $meta->model,
                        'type' => (string) Str::afterLast($name, '.'),
                        'route' => [],
                        'methods' => [],
                        'controller' => [],
                    ];
                }
                // An action shared by several routes is global only if every one of them is (fail closed).
                $actions[$name]['restrict'] = $actions[$name]['restrict'] || $restrict;
                $rows[$name]['route'][] = $meta->uri;
                array_push($rows[$name]['methods'], ...$meta->verbs);
                $rows[$name]['controller'][] = $meta->controllerAction;
            }
        }

        $this->report = $this->write($guard, $actions, (bool) $dry, $prune);

        return array_values(array_map(static fn (array $row) => [
            'permission' => $row['permission'],
            'model' => $row['model'],
            'type' => $row['type'],
            'route' => implode(', ', array_unique($row['route'])),
            'methods' => implode('|', array_unique($row['methods'])),
            'controller' => implode(', ', array_unique($row['controller'])),
        ], $rows));
    }

    /**
     * Counters and names of the last refresh(): created, updated, unchanged, pruned and orphans (actions no
     * route produces that are still granted to a role or model, so they were kept).
     *
     * @return array{created:int, updated:int, unchanged:int, pruned:string[], orphans:string[], dry_run:bool}
     */
    public function report(): array
    {
        return $this->report;
    }

    private function write(string $guard, array $actions, bool $dry, bool $prune): array
    {
        $model = app(config('permission.models.permission'));
        $table = $model->getTable();
        $connection = $model->getConnection();
        $existing = $connection->table($table)->where('guard_name', $guard)->get()->keyBy('name');

        $inserts = [];
        $updates = [];
        foreach ($actions as $name => $attributes) {
            $row = $existing->get($name);
            if ($row === null) {
                $inserts[] = $attributes;
            } elseif ($this->changed($row, $attributes)) {
                $updates[$row->{$model->getKeyName()}] = $attributes;
            }
        }
        $stale = $existing->keys()->diff(array_keys($actions))->values()->all();
        [$prunable, $orphans] = $prune ? $this->splitGranted($existing, $stale, $model) : [[], []];

        $report = [
            'created' => count($inserts),
            'updated' => count($updates),
            'unchanged' => count($actions) - count($inserts) - count($updates),
            'pruned' => array_values(array_keys($prunable)),
            'orphans' => $orphans,
            'dry_run' => $dry,
        ];
        if ($dry) {
            return $report;
        }

        $now = $model->usesTimestamps() ? $model->freshTimestampString() : null;
        $writes = $inserts !== [] || $updates !== [] || $prunable !== [];
        $writes && $connection->transaction(function () use ($connection, $table, $model, $inserts, $updates, $prunable, $now) {
            foreach (array_chunk($inserts, self::BATCH) as $chunk) {
                if ($now !== null) {
                    $chunk = array_map(fn (array $row) => $row + [
                        $model->getCreatedAtColumn() => $now,
                        $model->getUpdatedAtColumn() => $now,
                    ], $chunk);
                }
                $connection->table($table)->insert($chunk);
            }
            foreach ($updates as $id => $attributes) {
                if ($now !== null) {
                    $attributes[$model->getUpdatedAtColumn()] = $now;
                }
                $connection->table($table)->where($model->getKeyName(), $id)->update($attributes);
            }
            foreach (array_chunk(array_values($prunable), self::BATCH) as $ids) {
                $connection->table($table)->whereIn($model->getKeyName(), $ids)->delete();
            }
        });

        // Installed is not enough: without the Spatie provider the registrar has no configuration.
        if (class_exists(\Spatie\Permission\PermissionRegistrar::class) && app()->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        } else {
            Artisan::call('cache:forget', ['key' => 'spatie.permission.cache']);
        }
        return $report;
    }

    private function changed(object $row, array $attributes): bool
    {
        foreach ($attributes as $column => $value) {
            $current = $row->{$column} ?? null;
            if ($column === 'restrict') {
                // NULL counts as global, like the notRestricted() scope.
                if ((bool) $current !== $value || $current === null && $value) {
                    return true;
                }
                continue;
            }
            if ((string) $current !== (string) $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split stale actions into those nobody holds (prunable: name => id) and those still granted (orphans).
     *
     * @return array{0: array<string, mixed>, 1: string[]}
     */
    private function splitGranted($existing, array $stale, $model): array
    {
        if ($stale === []) {
            return [[], []];
        }
        $key = $model->getKeyName();
        $ids = array_map(static fn (string $name) => $existing->get($name)->{$key}, $stale);
        $pivotKey = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        $granted = [];
        $schema = $model->getConnection()->getSchemaBuilder();
        foreach (['role_has_permissions', 'model_has_permissions'] as $pivot) {
            $pivotTable = config("permission.table_names.$pivot") ?: $pivot;
            if (!$schema->hasTable($pivotTable)) {
                continue;
            }
            foreach (array_chunk($ids, self::BATCH) as $chunk) {
                $granted = array_merge($granted, $model->getConnection()->table($pivotTable)
                    ->whereIn($pivotKey, $chunk)->distinct()->pluck($pivotKey)->all());
            }
        }
        $granted = array_flip(array_map('strval', $granted));
        $prunable = [];
        $orphans = [];
        foreach ($stale as $i => $name) {
            isset($granted[(string) $ids[$i]]) ? $orphans[] = $name : $prunable[$name] = $ids[$i];
        }
        return [$prunable, $orphans];
    }
}
