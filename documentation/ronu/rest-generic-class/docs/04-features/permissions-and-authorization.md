# Permissions y autorización (Spatie)

## Overview
El paquete incluye middleware y traits para integrar `spatie/laravel-permission`, con soporte opcional para módulos (`nwidart/laravel-modules`) y endpoints para asignar permisos a roles o usuarios. 

## When to use / When NOT to use
**Úsalo cuando:**
- Necesitas autorización basada en permisos de Spatie.
- Quieres derivar permisos automáticamente desde rutas/controladores.

**No lo uses cuando:**
- No utilizas `spatie/laravel-permission` o no quieres autorización automática en middleware.

## How it works
- `SpatieAuthorize` resuelve el permiso requerido desde metadata de ruta, nombre de route o controller/method.
- `HasPermissionsController` expone endpoints de asignación `assign_roles` y `assign_users`.
- `HasPermissionsService` coordina `ADD/SYNC/REVOKE` y limpia el cache de permisos.
- Traits `HasReadableUserPermissions` y `HasReadableRolePermissions` exponen helpers de lectura.

## Configuration
- Requiere configuración de Spatie (`permission.php`) en la app huésped.
- El guard se resuelve desde `auth.defaults.guard` o parámetros de middleware/ruta.

## Usage examples
```php
Route::middleware('spatie.authorize:api')
    ->apiResource('articles', ArticleController::class);
```

```php
// En un controller
use Ronu\RestGenericClass\Core\Traits\HasPermissionsController;

class PermissionsController
{
    use HasPermissionsController;
}
```

## Edge cases / pitfalls
- Si el usuario no posee el permiso requerido, se retorna 403.
- `HasPermissionsService` espera que existan modelos de permiso/rol definidos por Spatie.
- Si el permiso no existe en cache, la validación puede fallar según configuración interna del app.

## Evidence
- File: src/Core/Middleware/SpatieAuthorize.php
  - Symbol: SpatieAuthorize::handle()
  - Notes: resolución de permisos y abort 403.
- File: src/Core/Resolvers/RouteMetaResolver.php
  - Symbol: RouteMetaResolver::resolve()
  - Notes: construcción de canonical permission name.
- File: src/Core/Traits/HasPermissionsController.php
  - Symbol: HasPermissionsController::{assign_roles,assign_users}
  - Notes: endpoints de asignación.
- File: src/Core/Traits/HasPermissionsService.php
  - Symbol: HasPermissionsService::{assignPermissionToRoles,assignPermissionToUsers}
  - Notes: operaciones ADD/SYNC/REVOKE.
- File: src/Core/Traits/HasReadableUserPermissions.php
  - Symbol: HasReadableUserPermissions::{effectivePermissions,permissionsFiltered}
  - Notes: lectura de permisos efectivos.
- File: src/Core/Traits/HasReadableRolePermissions.php
  - Symbol: HasReadableRolePermissions::permissionsFiltered()
  - Notes: filtrado por guard/módulo/entidad.
