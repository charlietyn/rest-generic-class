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
