<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Nwidart\Modules\Facades\Module;
use Ronu\RestGenericClass\Core\Resolvers\RouteMetaResolver;

/**
 * Syncs the permission catalog from the application's registered routes.
 *
 * Extracted from HasPermissionsService so route-derived permission creation is
 * isolated from role/permission assignment concerns (SRP).
 */
final class RoutePermissionRefresher
{
    public function __construct(
        private string $generalModule = '--site--'
    ) {
    }

    /**
     * Rebuild the permission entries derived from the route table.
     *
     * @param string $guard Guard name to stamp on created/updated permissions.
     * @param bool   $dry   When true, only reports rows without persisting.
     * @return array<int, array<string, mixed>>
     */
    public function refresh($guard, $dry): array
    {
        Artisan::call('cache:forget spatie.permission.cache');
        $cfg = config('route-permissions', []);
        $permissionClass = app(config('permission.models.permission'));
        $routes = Route::getRoutes();
        $modules = Module::toCollection()->map->getName()->values()->all();
        $rows = [];
        /** @var RouteMetaResolver $resolver */
        $resolver = app(RouteMetaResolver::class);
        foreach ($routes as $route) {
            $meta = $resolver->resolveFromRoute($route, $guard, $modules, $cfg);
            if (!$meta) {
                continue;
            }
            $permissionName = $meta->canonicalName;

            $rows[] = [
                'permission' => $permissionName,
                'model' => $meta->model,
                'type' => $meta->action,
                'route' => $meta->uri,
                'methods' => implode('|', $meta->verbs),
                'controller' => $meta->controllerAction,
            ];
            if ($dry) {
                continue;
            }
            $attributes = [
                'name' => $permissionName,
                'guard_name' => $guard,
                'module' => $meta->module,
                'route' => $meta->uri,
                'type' => $meta->action,
                'model' => $meta->model,
                'restrict' => $meta->module !== $this->generalModule,
                'action' => $meta->controllerAction,
            ];
            $permission_entity = $permissionClass::query()->where(['name' => $permissionName])->get()->first();
            if (!$permission_entity) {
                $permissionClass::create($attributes);
            } else {
                $permission_entity->fill($attributes)->save();
            }
        }
        return collect($rows)->unique('permission')->values()->all();
    }
}
