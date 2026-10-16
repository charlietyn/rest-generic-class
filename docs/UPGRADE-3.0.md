# Upgrading to rest-generic-class 3.0 / Actualizar a rest-generic-class 3.0

## English

3.0 removes the route permissions from this package; they now live in
[`ronu/rest-permissions`](https://github.com/charlietyn/rest-permissions). The CRUD API, filtering,
relations, cache, aggregates and validation are unchanged.

### You use permissions (SpatieAuthorize, SpatiePermission/SpatieRole, HasReadableUserPermissions, …)

1. Require both packages:

   ```bash
   composer require ronu/rest-generic-class:^3.0 ronu/rest-permissions:^1.0
   ```

2. That is enough to keep running, unchanged:
   - every 2.x class name under `Ronu\RestGenericClass\Core\…` that moved is aliased (lazily, with an
     `E_USER_DEPRECATED` notice) to `Ronu\RestPermissions\…` for the whole rest-permissions 1.x line;
   - the `permissions` block of an existing `config/rest-generic-class.php` is still applied while
     `config/rest-permissions.php` is not published; `REST_AUTHZ_*` / `REST_PERMISSIONS_*` keep their names;
   - permission route names stay `rest-generic-class.permissions.*`.
3. Then, at your pace, remove the deprecations:
   - rename imports: `Ronu\RestGenericClass\Core\Support\Permissions\…` → `Ronu\RestPermissions\Support\Permissions\…`,
     `…\Core\Middleware\SpatieAuthorize` → `Ronu\RestPermissions\Middleware\SpatieAuthorize`,
     `…\Core\Models\SpatiePermission|SpatieRole` → `Ronu\RestPermissions\Models\…`, `…\Core\Traits\HasPermissions*` /
     `HasReadable*` → `Ronu\RestPermissions\Traits\…`, `…\Core\Resolvers\…` → `Ronu\RestPermissions\Resolvers\…`;
   - `php artisan vendor:publish --tag=rest-permissions-config` and move the values of `permissions` there
     (top level: `authorization`, `roles_relation`, `routes`, `contracts`), then delete the block;
   - move `permissions.authorization.eager_relations` / `eager_relations_to_one` to `authorization.*` in
     `config/rest-generic-class.php` (the old keys are still read as a fallback);
   - the migration script is now `vendor/ronu/rest-permissions/scripts/enable-channel-authorization.php`.

### You do not use permissions

Nothing to do. `spatie/laravel-permission` is no longer suggested nor constrained by this package. If you set
`authorization.eager_relations=require_read` without a permission integration, the core now fails closed
(`MissingAuthorizationContextException`): bind an `AuthorizationContext` or use `off`. See the
[authorization contracts](../documentacion/doc-en/04-reference/06-authorization-contracts.md).

### Removed from this package

`Core\Support\Permissions\*`, `Core\Support\Authorization\{EagerRelationAuthorizer, EffectivePermissionGate,
PermissionDecisionEvaluator, RequiredPermissionResolver, RouteAuthorizationContext}`, `Core\Resolvers\*`,
`Core\Middleware\SpatieAuthorize`, `Core\Models\{SpatiePermission, SpatieRole}`, `Core\Traits\{HasPermissionsService,
HasPermissionsController, HasReadableUserPermissions, HasReadableRolePermissions, NormalizesSpatieNames}`,
`Core\Controllers\PermissionsController`, `routes/permissions.php`, `scripts/*`, the `permissions` config block and
the Spatie entries of `composer.json`. The core binds `NullAuthorizationContext` by default.

## Español

3.0 saca de este paquete los permisos por rutas, que pasan a
[`ronu/rest-permissions`](https://github.com/charlietyn/rest-permissions). La API CRUD, el filtrado, las
relaciones, la caché, los agregados y la validación no cambian.

### Si usas permisos

1. `composer require ronu/rest-generic-class:^3.0 ronu/rest-permissions:^1.0`.
2. Con eso sigue funcionando igual: alias perezosos de los nombres 2.x (con aviso de obsolescencia) durante toda
   la línea 1.x del plugin, el bloque `permissions` de `config/rest-generic-class.php` se aplica mientras no se
   publique `config/rest-permissions.php`, mismas variables de entorno y mismos nombres de ruta.
3. Después, sin prisa: renombra los imports a `Ronu\RestPermissions\…`, publica `rest-permissions-config` y mueve
   allí los valores de `permissions`, mueve `eager_relations*` a `authorization.*`, y usa el script desde
   `vendor/ronu/rest-permissions/scripts/`.

### Si no usas permisos

No hay que hacer nada. Si configuras `authorization.eager_relations=require_read` sin integración de permisos,
el núcleo falla de forma cerrada (`MissingAuthorizationContextException`).
