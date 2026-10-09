# v2.6.0 — Channel authorization / Autorización por canales

Release date / Fecha: 2026-10-09.

## Español

**Si usas prefijos de ruta que no son el nombre del guard, tu autorización estaba desactivada**
en las rutas que dependían de `SpatieAuthorize` con la configuración legacy. La autenticación
por sí sola no comprobaba los permisos de esas rutas. Esta versión permite corregirlo
sin añadir dependencias ni cambiar las firmas públicas de los traits o resolvers.

### Cambios

- `permissions.authorization` permite seleccionar prefijos por canal y usar el nombre
  literal de la ruta como permiso. `admin` no coincide con `administration`.
- El catálogo y el middleware comparten una única resolución, reconocen `ModSecurity`
  como `mod_security` y respetan el guard real de autenticación.
- La sincronización busca por `(name, guard_name)` para evitar sobrescrituras entre
  guards; `--dry-run` no escribe ni limpia la caché. Una sincronización real invalida
  la caché de Spatie después de guardar los cambios.
- `on_unresolved=deny` devuelve 403 cuando una ruta con el middleware no tiene permiso
  resoluble, incluso si queda fuera de los prefijos configurados.
- El Gate opcional permite usar `can()` con `HasReadableUserPermissions`. Concede por
  nombre efectivo exacto; devuelve `null` en los demás casos para respetar otras
  policies. Los modelos con `checkPermissionTo()` siguen usando Spatie.
- Caché por objeto usuario/guard durante la petición, con servicio `scoped` y limpieza
  mediante `RequestHandled`. No comparte permisos por coincidencia de IDs.

### Compatibilidad y decisiones

Se mantienen los valores `route_prefixes=null`, `naming=derived`,
`on_unresolved=allow`, `register_gate=false` y `gate_guard=null`.
`allow` queda obsoleto por su comportamiento fail-open; se prevé `deny` como valor
predeterminado en v3.0.0. Actualizar la biblioteca no activa automáticamente la protección
por canales: hay que configurar la aplicación.

Hay correcciones observables al unificar catálogo y middleware: el modo derivado ahora
incluye la versión de la ruta también al autorizar y considera no resueltas las rutas
sin controlador, igual que el catálogo. Revisa estas rutas antes de actualizar; usa
`route_name` para closures con nombre. En ese modo no se traducen `store`/`destroy`,
no se antepone la versión y no se excluyen los nombres `.create`/`.edit`.

Para conciliar los permisos globales del plan con la compatibilidad, su incorporación
al lector efectivo se activa con `register_gate=true`. Entonces se incluyen los permisos
del scope `notRestricted()` (en el modelo del paquete: `false`, `0` o `null`), aun sin roles,
filtrados por guard y deduplicados por ID. Sin ese ajuste se conserva la unión legacy.
Un global se concede a cualquier usuario autenticado del guard, incluso de otro canal.
La sincronización marca como globales las rutas sin módulo reconocido: revisa especialmente
`admin.auth.*` y las aplicaciones sin descubrimiento de módulos.

La caché evita repetir la carga de permisos efectivos; esa carga puede ejecutar varias
consultas SQL. Si se cambian permisos o el contexto de tenant dentro de la misma petición,
limpia `app(\Ronu\RestGenericClass\Core\Support\Authorization\EffectivePermissionGate::class)->clear()`.
No se interpretan comodines de presentación ni se crean asignaciones por nombre de rol.
`passesFilters()` sigue siendo un punto de extensión; se elimina la lectura de la clave
inexistente `route-permissions` y se utiliza la configuración pública del paquete.

### Activación segura

```php
// config/rest-generic-class.php -> permissions
'authorization' => [
    'route_prefixes' => ['admin', 'client', 'mobile'],
    'naming' => 'route_name',
    'on_unresolved' => 'deny',
    'register_gate' => true,
    'gate_guard' => 'api',
],
```

1. Prepara el cambio durante mantenimiento, manteniendo cerrado el tráfico protegido.
2. Configura prefijos y nombres, revisa `permissions:sync-from-routes --guard=api --dry-run --dump`
   y ejecuta la sincronización real.
3. Asigna los permisos necesarios a los roles antes de habilitar el Gate y `deny`.
4. Activa la configuración completa, limpia las cachés y verifica accesos permitidos
   y denegados entre canales antes de reabrir el tráfico.

La biblioteca no migra datos ni roles de las aplicaciones consumidoras.

## English

**If your route prefixes differ from the guard name, authorization was skipped**
on routes relying on legacy `SpatieAuthorize`. Authentication alone did not enforce
their permissions. v2.6.0 adds explicit channel prefixes, literal route permission names,
an unresolved-route deny mode, and an optional effective-permission Gate without new dependencies.

Synchronization and middleware share one resolver. Module names accept snake case;
permissions are updated by `(name, guard_name)`. Dry runs do not persist or clear caches;
real synchronization invalidates Spatie's permission cache after writes.

Defaults remain `null` / `derived` / `allow` / `false` / `null` for prefixes, naming,
unresolved behavior, Gate registration and Gate guard. Fail-open `allow` is deprecated;
v3.0.0 is intended to default to `deny`. Upgrading alone does not secure channel routes.
With `deny`, any unresolved route carrying the middleware returns 403, including routes
outside the configured scope.

Resolver alignment fixes two legacy inconsistencies: derived middleware names now include
route version metadata, and controllerless routes are unresolved in both paths. Review
those routes before upgrading. Use `route_name` for named closures. That mode preserves
literal action suffixes, does not prepend versions and includes `.create`/`.edit` names.

The optional Gate grants exact effective names and otherwise returns `null` so policies
continue. Users exposing Spatie's `checkPermissionTo()` are skipped. `register_gate=true`
also enables the global `notRestricted()` union in the shared effective reader; otherwise
the legacy direct/role union is retained. Globals apply to every authenticated user of
the selected guard, across channels, including users with no roles. Sync marks routes
without a recognized module as global, so review `admin.auth.*` and module discovery.
The package scope treats `false`, `0` and `null` restrictions as global.

Effective sets are cached per user object/guard per request, using scoped storage plus
`RequestHandled` cleanup. One effective load can issue multiple SQL queries. Clear the
service if permissions or tenant context change within a request. Presentation wildcards
are not interpreted as grants. `passesFilters()` remains an extension hook; the obsolete
`route-permissions` key is replaced by the public package configuration.

Use the configuration above. During maintenance, configure naming/prefixes, preview and
sync permissions, assign roles, then enable the Gate and `deny`, clear caches and test
cross-channel access before restoring traffic. Consumer data and role assignments are
not migrated by the library.

## Validation / Validación

The regression suite covers legacy/channel scope, naming and version parity, named closures,
middleware 403 responses, cross-channel grants, global permissions, policy fallback,
Spatie delegation, request cache cleanup, scoped lifecycle and guard-safe synchronization.
See the repository CI matrix for PHP 8.2–8.5 and Laravel 12/13 coverage.

Local verification: 255 tests / 662 assertions passed with PHP 8.5 on both Laravel
13.12.0 and 13.35.0. Two pre-existing PHP nullable-parameter deprecations remain.
`composer validate --strict` passed. Updating local dependencies within the unchanged
Composer constraints cleared the initial audit advisories; `composer audit` now passes.
Audited CI pins use patched Laravel 12.69/13.30 releases rather than the vulnerable
12.61/13.12 pins. Public package dependency constraints remain unchanged.

[CI verification](https://github.com/charlietyn/rest-generic-class/actions/runs/37964181991):
all nine jobs passed, covering five PHP/Laravel combinations plus MySQL/PostgreSQL
aggregation checks on Laravel 12 and 13.

# v2.6.1 — Legacy module matching / Módulos legacy

Status / Estado: **released as part of v2.7.0; no separate v2.6.1 tag / publicada dentro de v2.7.0; sin tag v2.6.1 independiente**.

## Español

Corrige el cambio de nombres involuntario de v2.6.0 en modo legacy. Con
`route_prefixes=null`, el módulo vuelve a resolverse exactamente como en v2.5.0:
se exige `count($parts)>2`, el primer segmento debe ser el guard literal y el
nombre del módulo debe coincidir exactamente. Para el módulo `ModSecurity`,
`api/mod_security/users` produce `users.index`, mientras que
`api/ModSecurity/users` produce `ModSecurity.users.index`. `api/ModSecurity`
no tiene suficientes segmentos para reconocer un módulo. Solo los prefijos
explícitos activan la coincidencia snake_case; se conservan los prefijos anidados.

Si sincronizaste el catálogo legacy con v2.6.0, **vuelve a sincronizar y revisa
las asignaciones a los nombres restaurados**. La sincronización no renombra ni
borra filas antiguas: revisa las asignaciones antes de eliminarlas.
Se mantienen las correcciones de v2.6.0 relativas a versión y rutas sin controlador;
esta corrección restaura la inferencia de módulo, no aquellas inconsistencias antiguas.

La firma protegida `resolveModuleFromUri(string $uri, string $guard, array $modules)`
se conserva. A diferencia del pseudocódigo del plan, el parámetro opcional
`$matchSnake=false` vive en un helper privado: añadirlo al método heredable rompería
las subclases de tres argumentos por incompatibilidad de firma en PHP. El hook sigue
ejecutándose en ambos modos; su contexto se restaura mediante `finally`.
No cambian firmas públicas, dependencias ni la extensibilidad de las clases.

## English

Fixes the unintended legacy permission renaming in v2.6.0. With
`route_prefixes=null`, module inference follows v2.5.0 exactly: more than two
non-empty URI segments, the literal guard as the first segment, and an exact module
name. With module `ModSecurity`, `api/mod_security/users` yields `users.index` and
`api/ModSecurity/users` yields `ModSecurity.users.index`; `api/ModSecurity` is too short
to resolve a module. Snake-case matching requires explicit prefixes; nested channel
prefixes continue to work.

If you synchronized legacy permissions with v2.6.0, **resynchronize and review role
assignments to the restored names**. Sync does not rename or delete obsolete rows.
The version/controllerless-route alignment from v2.6.0 remains unchanged.

The existing three-argument protected extension hook is retained. PHP would reject
old overrides if the optional fourth argument were added to the parent signature.
The optional `$matchSnake=false` argument therefore lives in a private helper;
the original hook runs in both modes and its context is restored with `finally`.
No public signatures, dependencies or class extensibility change.

# v2.7.0 — Explicit globals and catalog scope / Globales y catálogo explícitos

Status / Estado: **released / publicada** (2026-10-09). Includes the v2.6.1 legacy fix above /
incluye la corrección legacy de v2.6.1 descrita arriba.

The pattern list `global_permissions` from the earlier draft was discarded before release and
replaced by `explicit_globals` plus the `permission_global` route action attribute.
El borrador previo con la lista de patrones `global_permissions` se descartó antes de publicar;
se sustituye por `explicit_globals` y el atributo de acción de ruta `permission_global`.

## Español

Dos opciones nuevas bajo `permissions.authorization`; con sus valores por defecto el
comportamiento es el de v2.6.0:

| Opción | Por defecto | Activada |
| --- | --- | --- |
| `explicit_globals` | `false`: las rutas sin módulo reconocido son globales (`restrict=false`). | `true`: solo las rutas cuya acción lleva `'permission_global' => true` son globales, incluso dentro de un módulo; el resto queda restringido (falla cerrando). |
| `catalog_middleware` | `null`: todas las rutas del ámbito del resolver. | Array: solo rutas cuyo `gatherMiddleware()` contiene un string que empieza por un marcador, alias o FQCN; `[]` no selecciona ninguna. |

Lo global se declara en la propia ruta, junto al código que lo justifica:

```php
Route::group(['as' => 'admin.', 'permission_global' => true], function () {
    Route::get('auth/permissions', ...)->name('auth.permissions');
    Route::get('auth/user-profile', ...)->name('auth.user-profile');
});

// Ruta suelta
$route = Route::get('auth/modules', ...)->name('admin.auth.modules');
$route->setAction([...$route->getAction(), 'permission_global' => true]);
```

No uses `->defaults('permission_global', true)`: Laravel convierte los defaults en parámetros
de ruta que llegan al controlador, y el resolver los ignora. `RouteMeta` incorpora la propiedad
opcional `public readonly bool $global = false` al final del constructor; las llamadas existentes
siguen funcionando.

`catalog_middleware` ignora entradas no string y acepta parámetros, por ejemplo
`maybe.permission:api` con marcador `maybe.permission`. No expande alias ni grupos.
El filtro se aplica únicamente en `RoutePermissionRefresher`, antes de resolver
la ruta, tanto en dry-run como en sincronización real. No altera `SpatieAuthorize`
ni `RouteMetaResolver`: excluir una ruta del catálogo no evita la comprobación de permisos.

Al sincronizar se actualiza `restrict` en las filas existentes seleccionadas,
conservando IDs y asignaciones. `UserPermissionReader` y el Gate no cambian: continúan
sumando los `notRestricted()` persistidos cuando `register_gate=true`.
Las filas excluidas por middleware quedan intactas, incluidas sus restricciones.
**No se eliminan permisos antiguos ni se añade `--prune`**: revisa y elimina manualmente
los que ya no deban existir; cambiar la configuración no revoca sus asignaciones.

```dotenv
REST_AUTHZ_EXPLICIT_GLOBALS=true
REST_AUTHZ_CATALOG_MIDDLEWARE=maybe.permission
```

`REST_AUTHZ_CATALOG_MIDDLEWARE` sin definir equivale a `null` y vacía a `[]`; los valores se
separan por comas y se recortan. Actualiza la caché de configuración y resincroniza para aplicar
las opciones. Antes de activar `explicit_globals`, marca con `permission_global` las rutas que
deban seguir siendo globales: tras resincronizar, el resto pasa a estar restringido.

## English

Adds two opt-in `permissions.authorization` options; defaults keep v2.6.0 behaviour:

| Option | Default | Enabled |
| --- | --- | --- |
| `explicit_globals` | `false`: routes without a recognized module are global (`restrict=false`). | `true`: only routes whose action carries `'permission_global' => true` are global, even inside a module; everything else is restricted (fail closed). |
| `catalog_middleware` | `null`: every route in resolver scope. | Array: routes with a string in `gatherMiddleware()` starting with an alias/FQCN marker; `[]` selects none. |

Declare globals on the route itself, as a `Route::group()` attribute or with
`setAction([...$route->getAction(), 'permission_global' => true])` (see the example above).
Do not use `->defaults()`: Laravel turns defaults into route parameters passed to controllers,
and the resolver ignores them. `RouteMeta` gains a trailing optional
`public readonly bool $global = false`; existing constructor calls keep working.

Middleware markers accept parameters (`maybe.permission` matches `maybe.permission:api`),
ignore non-string entries and do not expand aliases/groups. The filter runs only in
`RoutePermissionRefresher`, before resolution, for dry runs and real writes.
Runtime resolver and middleware authorization remain unchanged.

Resync updates restrictions on existing selected rows without changing IDs/assignments.
The effective reader and Gate continue using stored `notRestricted()` flags when enabled.
Excluded rows retain their previous restrictions. There is no deletion or `--prune`;
review and remove obsolete permissions manually. Configuration alone does not revoke grants.

Use the environment variables shown above (`REST_AUTHZ_CATALOG_MIDDLEWARE`: unset means `null`,
empty means `[]`, comma-separated entries are trimmed). Refresh configuration caches and
resynchronize. Mark routes that must stay global before enabling `explicit_globals`.
No dependency changes are included.

## Phase 2 verification / Validación de fase 2

`vendor/bin/phpunit`: **275 tests, 730 assertions, zero failures/errors**.
Two pre-existing `BaseModel` implicit-nullability deprecations remain.
Tests cover exact legacy names/counts, channel matching and extension compatibility,
`explicit_globals` on/off with resync, group and `setAction()` attributes, `->defaults()` being
ignored and not leaking into controller parameters, `RouteMeta` backward compatibility,
null/empty catalog settings, alias/FQCN matching, non-string middleware, preservation of
excluded rows and runtime authorization independent of catalog filtering.

# v2.7.1 — Fixes found while migrating kwikvet / Correcciones de la migración de kwikvet

Status / Estado: **released / publicada** (2026-10-09).
Patch release: no behaviour changes with the default configuration.
Versión de parche: sin cambios de comportamiento con la configuración por defecto.

## Español

- **`GET /<canal>/modules` respondía siempre 500** (`Call to undefined method ...PermissionsService::getModules()`):
  `HasPermissionsController::modules()` llamaba al servicio en vez de al método `getModules()` del propio trait.
  Ya fallaba en v2.5.0, pero quedaba oculto hasta activar la autorización. Ahora:
  - `?enabled=true|1|on` devuelve solo los módulos habilitados, `false|0|off` solo los deshabilitados;
    ausente, vacío o un valor no reconocido devuelve todos. El texto se convierte de forma explícita a `?bool`.
  - Nuevo `?format=uri`: devuelve el segmento de URI de cada módulo (`ModSecurity` → `mod_security`), que es
    lo que guarda la columna `permissions.module` con `naming=route_name`. Sin el parámetro, la respuesta
    sigue siendo el array de nombres de nwidart más `--site--`.
  - Sin `nwidart/laravel-modules` instalado devuelve `['--site--']` en vez de un error, como ya hacían
    `SpatieAuthorize` y `RoutePermissionRefresher`. El acceso a nwidart queda en el hook
    `protected static moduleCollection()`, sobrescribible.
- **Parámetros nullable explícitos** (`?string`, `?array`, `?bool`) en `BaseModel`, `BaseModelMongo` y
  `HasPermissionsController::getModules()`: desaparecen los avisos de PHP ≥ 8.4 por nullable implícito.
  La firma efectiva no cambia, así que las subclases no se ven afectadas.
- **Script `scripts/enable-channel-authorization.php`**, distribuido con la biblioteca
  ([guía](../documentacion/doc-es/03-usage/07-enable-channel-authorization-script.md)):
  - el informe de `--apply` muestra en cada archivo de rutas solo los nombres de su canal (antes listaba los
    de todos los canales en cada archivo; los cambios en disco ya eran correctos);
  - detecta también las declaraciones que van justo después de `<?php`;
  - `--grant=Rol:@archivo.json` (o `.yml`) asigna una lista explícita de permisos mediante
    `roles:assign-permissions --from`, para cuando conceder un prefijo entero da demasiado;
  - al terminar, o si falla un paso, lista los pasos de `--apply` completados (`✔`), el interrumpido (`✘`)
    y los pendientes (`·`).
  - Las funciones puras están en `scripts/channel-authorization-functions.php` y tienen tests.

## English

- **`GET /<channel>/modules` always returned 500**: `HasPermissionsController::modules()` called the service
  instead of the trait's own `getModules()`. It now converts `?enabled=` explicitly (`true|1|on`, `false|0|off`;
  absent, empty or unrecognized means all), adds `?format=uri` (`ModSecurity` → `mod_security`, the value stored
  in `permissions.module` with `naming=route_name`) and returns `['--site--']` without nwidart instead of failing.
  The nwidart lookup lives in the overridable `protected static moduleCollection()` hook.
- **Explicit nullable parameters** in `BaseModel`, `BaseModelMongo` and `getModules()`: no more PHP ≥ 8.4
  implicit-nullable deprecations. The effective signatures are unchanged, so subclasses are unaffected.
- **`scripts/enable-channel-authorization.php`** now ships with the library
  ([guide](../documentacion/doc-en/03-usage/07-enable-channel-authorization-script.md)): per-file `--apply`
  report with only that channel's names, detection of declarations right after `<?php`,
  `--grant=Role:@file.json` for explicit permission lists, and a completed/interrupted step summary.

`vendor/bin/phpunit --display-deprecations`: **289 tests, 790 assertions, no failures, errors or deprecations**.
