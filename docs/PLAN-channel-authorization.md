# Plan — Autorización por canales en `ronu/rest-generic-class` (v2.6.0)

> Estado: **BIBLIOTECA IMPLEMENTADA Y VALIDADA PARA v2.6.0; MIGRACIÓN DE KWIKVET PENDIENTE**. Redactado el 2026-10-09 a partir de una investigación en
> `D:\www\kwikvet`. Base: `main` @ `24faa1b` (v2.5.0), idéntico a `kwikvet/vendor/ronu/rest-generic-class`
> salvo por finales de línea (CRLF).
>
> Plan original conservado en `docs/`. Ejecución: configuración, resolver compartido, middleware, sincronización, Gate opcional, pruebas y documentación en/es.
> Decisiones finales y diferencias de compatibilidad: [notas v2.6.0](release-notes-channel-authorization.md).
> La incorporación de globales se activa con `register_gate` para conservar la unión legacy por defecto.
> El middleware derivado adopta versión/exclusiones del catálogo; esas correcciones se documentan expresamente.
> Kwikvet no se ha modificado: su migración es una fase independiente pendiente de confirmación del alcance.
>
> **Fase 2 (§7): implementada y publicada en v2.7.0** (incluye la corrección legacy de §7.1; sin tag v2.6.1 independiente).
> Se implementó `explicit_globals` + atributo `permission_global` (§7.2); la lista de patrones `global_permissions` se descartó.
> La §5 (kwikvet) se revisará cuando se publique la fase 2.

---

## 1. Problema

### 1.1 El middleware deja pasar las rutas que no reconoce (fail-open)

`SpatieAuthorize::handle()` → `RouteMetaResolver::resolve()`:

```php
$prefix = (string)$route->getPrefix();
if (!Str::startsWith($prefix, $guard)) {
    return null;            // → SpatieAuthorize hace $next($request): NO se comprueba nada
}
```

La biblioteca da por hecho que toda ruta protegida empieza por el nombre del guard (`api/...`). Con
`ronu/laravel-app-context` las rutas empiezan por el **canal** (`admin/`, `client/`, `mobile/`), así que
el resolver devuelve `null` en todas y el middleware no aplica ningún permiso.

**Comprobado en kwikvet** (tokens reales emitidos por `TokenService`, 0 permisos en la base de datos):

| Usuario | Petición | Resultado |
|---|---|---|
| #15, rol `Client` | `GET /admin/mod_security/users` | 200 (lista de usuarios con emails) |
| #15, rol `Client` | `GET /client/mod_security/users` | 200 |
| #12, rol `Technician` | `GET /admin/mod_security/roles` | 200 |

### 1.2 El comando de sincronización genera 0 permisos

`RoutePermissionRefresher` usa `RouteMetaResolver::resolveFromRoute()`, que tiene la misma comprobación
de prefijo. Con `--guard=api` salen 0 permisos. Con `--guard=admin` salen 446, pero:

- **Sin canal ni módulo en el nombre:** `roles.index` en vez de `admin.mod_security.roles.index`.
  `resolveModuleFromUri()` compara el segmento `mod_security` con los nombres de módulo de nwidart
  (`ModSecurity`) y nunca coinciden.
- **Los canales se pisan entre sí:** 443 nombres son iguales en admin y en client, y el refresher busca
  solo por `name`, así que cada ejecución sobrescribe la anterior (también el `guard_name`).
- **`guard_name` queda como `admin`, `mobile` o `client`,** que no son guards reales.

### 1.3 `can()` siempre devuelve `false` con `HasReadableUserPermissions`

Los modelos que usan `HasReadableUserPermissions` (en lugar de `HasRoles` de Spatie) no tienen
`checkPermissionTo()`. El `Gate::before` de Spatie (`PermissionRegistrar::registerPermissions`) solo
actúa si ese método existe, así que devuelve `null` y `$user->can('x')` da `false`.

Consecuencia: **si se arregla solo el punto 1.1, todos los usuarios (incluido el Administrator) reciben
403 en todo.**

### 1.4 Incoherencias que aparecieron durante la investigación

- `resolve()` (middleware) y `resolveFromRoute()` (catálogo) están duplicados y **no calculan el mismo
  nombre**: `resolveFromRoute()` antepone la `version` de la ruta y descarta las rutas sin controlador;
  `resolve()` no hace ninguna de las dos cosas. El permiso que exige el middleware puede no existir en el
  catálogo.
- `RouteMetaResolver::passesFilters()` recibe `$cfg` pero siempre devuelve `true`, y el refresher lee
  `config('route-permissions')`, que no existe en ningún sitio.
- Permisos globales (`restrict = false`): `getPermissionsByRoles()` los suma a cada rol, pero
  `UserPermissionReader::effectivePermissions()` no. Un permiso «global» aparece en el catálogo del rol,
  pero no lo tiene el usuario.

---

## 2. Diseño

Configurable y **compatible hacia atrás**: con la configuración por defecto, v2.6.0 se comporta igual
que v2.5.0. Un proyecto con canales lo activa desde su propia configuración.

### 2.1 Nueva configuración — `config/rest-generic-class.php` → `permissions.authorization`

```php
'authorization' => [
    // Prefijos de URI en los que se aplica la autorización.
    //   null  → comportamiento actual: el prefijo debe empezar por el nombre del guard.
    //   array → lista de prefijos (p. ej. ['admin', 'client', 'mobile']); el guard deja de filtrar
    //           y solo sirve para el guard_name de los permisos y para auth($guard).
    'route_prefixes' => null,

    // Cómo se calcula el nombre del permiso.
    //   'derived'    → comportamiento actual: {version}.{module}.{model}.{action}, inferido.
    //   'route_name' → el nombre de la ruta tal cual (p. ej. admin.mod_security.users.index).
    //                  Una ruta sin nombre se trata como «no resuelta» (ver on_unresolved).
    'naming' => 'derived',

    // Qué hace el middleware cuando una ruta protegida no da ningún permiso.
    //   'allow' → comportamiento actual (fail-open). Se mantiene por defecto solo por compatibilidad.
    //   'deny'  → 403. Recomendado.
    'on_unresolved' => 'allow',

    // Registra un Gate::before para los modelos que usan HasReadableUserPermissions, de modo que
    // $user->can('perm') funcione sin el trait HasRoles de Spatie.
    'register_gate' => false,

    // Guard con el que el Gate filtra los permisos efectivos. null = cualquier guard.
    'gate_guard' => null,
],
```

Variables de entorno: `REST_AUTHZ_ROUTE_PREFIXES` (separadas por comas), `REST_AUTHZ_NAMING`,
`REST_AUTHZ_ON_UNRESOLVED`, `REST_AUTHZ_REGISTER_GATE`, `REST_AUTHZ_GATE_GUARD`.

**Sin dependencia de `ronu/laravel-app-context`.** La biblioteca solo recibe una lista de prefijos; cada
app la rellena como quiera (a mano o leyendo `config('app-context.channels.*.path_prefixes')`).

### 2.2 `RouteMetaResolver`

1. **Una sola implementación.** `resolve(Request …)` pasa a ser
   `return $request->route() ? $this->resolveFromRoute($request->route(), …) : null;`.
   Así el middleware y el catálogo calculan siempre el mismo nombre (corrige 1.4, primer punto).
2. **El ámbito, en un método aparte que se pueda sobrescribir:**
   `protected function matchedPrefix(Route $route, string $guard, array $cfg): ?string`
   - Si `route_prefixes` es `null`, devuelve `$guard` cuando `getPrefix()` empieza por él (lo de hoy).
   - Si es un array, devuelve el prefijo cuyo primer segmento de la URI coincide **exactamente**
     (`admin` sí casa con `admin/...`, pero no con `administration/...`).
   - Si no hay coincidencia, devuelve `null` y la ruta no está en el ámbito.
3. **Resolución de módulo** (`resolveModuleFromUri`): comparar el segmento que va después del prefijo
   encontrado (no `parts[0] === $guard`) y aceptar tanto el nombre exacto como el `snake_case` del módulo
   (`ModSecurity` ↔ `mod_security`). Devolver el segmento tal como aparece en la URI.
4. **`naming = 'route_name'`:** `canonicalName = $route->getName()`. Sin nombre devuelve `null`.
   Se conservan `module`, `model` y `action` inferidos para las columnas del catálogo.
   → Ver decisión **D1** (el verbo de la acción).
5. `RouteMeta` sigue igual. Añadir un campo `scope` (el prefijo encontrado) solo si hace falta para el
   refresher.
6. No hacer la clase `final`: las apps que ya la extienden no deben romperse.

### 2.3 `SpatieAuthorize`

```php
$meta = $resolver->resolve($request, $guard, $modules, $cfg);
if (!$meta) {
    if (config('rest-generic-class.permissions.authorization.on_unresolved') === 'deny') {
        abort(403, config('app.debug') ? 'Forbidden: route has no resolvable permission' : 'Forbidden');
    }
    return $next($request);
}
```

Pasar la configuración real como `$cfg` (hoy se pasa `[]`).

### 2.4 `RoutePermissionRefresher`

- Con `route_prefixes` definido, `--guard` solo da el `guard_name`, no filtra.
- Buscar el permiso existente por **`name` + `guard_name`** (hoy solo por `name`).
- Leer la configuración de la nueva clave (`config('route-permissions')` no existe). Mantener la clave
  antigua como alternativa si se quiere compatibilidad, pero dejarla documentada.
- `restrict`: hoy es `module !== '--site--'`. En `route_name`, las rutas de la app sin módulo
  (`admin.auth.permissions`, `admin.auth.user-profile`, `admin.auth.modules`) quedan como globales
  (`restrict = false`). Ver decisión **D3**.

### 2.5 Gate para `HasReadableUserPermissions`

En `RestGenericClassServiceProvider::boot()`, solo si `register_gate = true`:

```php
Gate::before(function ($user, string $ability) {
    if (method_exists($user, 'checkPermissionTo')) {
        return null;                       // ya lo resuelve Spatie
    }
    if (!method_exists($user, 'effectivePermissions')) {
        return null;
    }
    return app(EffectivePermissionGate::class)->allows($user, $ability) ?: null;
});
```

- `EffectivePermissionGate` (nuevo, `Core/Support/Authorization/`) carga una vez los nombres efectivos
  de cada usuario por petición: un array indexado por `getAuthIdentifier()` en un singleton que se vacía
  con el evento `RequestHandled` (o con `scoped()` para Octane).
- Devuelve `null` (no `false`) cuando no concede, para que sigan funcionando otras policies o gates.
- Incluir los permisos globales (`restrict = false`) según **D3**.

### 2.6 Lo que NO cambia

- Valores por defecto: `null` / `derived` / `allow` / `false` = comportamiento de v2.5.0.
- `RouteMeta`, las firmas públicas de `resolve()` / `resolveFromRoute()`, los comandos y los traits.
- Ninguna dependencia nueva en `composer.json`.

---

## 3. Decisiones pendientes (resolver antes de ejecutar)

| # | Decisión | Opciones | Recomendación |
|---|---|---|---|
| **D1** | Verbo de la acción con `naming = route_name` | a) tal cual sale de la ruta: `index/show/store/update/destroy`. b) traducido con `restToPermMap`: `store→create`, `destroy→delete` | **a)** El nombre del permiso es el de la ruta, sin reglas extra. Si algún frontend ya usa `create`/`delete`, ofrecer b) como `'route_name_mapped'` |
| **D2** | ¿v2.6.0 (minor) o v3.0.0? | Con los valores por defecto antiguos no se rompe nada, así que basta con minor | **v2.6.0** y avisar en las notas de versión que `on_unresolved=allow` es inseguro |
| **D3** | Permisos globales (`restrict=false`) en el Gate | a) cualquier usuario autenticado los tiene. b) solo si se asignan | **a)** Coincide con `getPermissionsByRoles()` y resuelve `admin.auth.*` sin asignarlos rol por rol. Unificar `effectivePermissions()` con ese mismo criterio (o documentar por qué no) |
| **D4** | ¿Cambiar el valor por defecto de `on_unresolved` a `deny` en una versión mayor futura? | — | Anunciarlo como obsoleto en v2.6.0 y cambiarlo en v3 |
| **D5** | Qué permisos recibe cada rol en kwikvet | Decisión de negocio | Administrator: `admin.*`. Client: `client.*`. Veterinarian/Technician: `mobile.*`, como base para afinar |

---

## 4. Pasos — biblioteca (`ronu-rest-generic-class`)

1. Rama: `git checkout -b feat/channel-scoped-authorization` desde `main`.
2. `config/rest-generic-class.php`: añadir `permissions.authorization` (§2.1).
3. `src/Core/Resolvers/RouteMetaResolver.php`: §2.2.
4. `src/Core/Middleware/SpatieAuthorize.php`: §2.3.
5. `src/Core/Support/Permissions/RoutePermissionRefresher.php`: §2.4.
6. Nuevo `src/Core/Support/Authorization/EffectivePermissionGate.php` y registrarlo en
   `src/Core/Providers/RestGenericClassServiceProvider.php` (§2.5).
7. **Tests** (`tests/Unit`, siguiendo el estilo de `AuthorizationSupportTest`, con Container y Config
   sin la app completa):
   - `RouteMetaResolverScopeTest`
     - legacy: prefijo `api/...` con guard `api` → resuelve; `admin/...` → `null` (se fija el comportamiento actual).
     - `route_prefixes=['admin','client']`: `admin/mod_security/users` resuelve; `administration/x` no; `other/x` no.
     - módulo: `mod_security` casa con el módulo `ModSecurity`.
     - `naming=route_name`: canonical = nombre de la ruta; ruta sin nombre → `null`.
     - `resolve()` y `resolveFromRoute()` devuelven el mismo `canonicalName` para la misma ruta (también con `version`).
   - `SpatieAuthorizeTest`
     - `on_unresolved=allow` → `$next`; `deny` → 403.
     - usuario sin el permiso → 403; con el permiso → `$next`.
   - `EffectivePermissionGateTest`
     - el modelo con `HasReadableUserPermissions` obtiene `true` para un permiso efectivo y `null` para el resto.
     - un modelo con `checkPermissionTo` → el Gate devuelve `null` (no interfiere con Spatie).
     - permisos globales según D3.
     - una sola consulta por usuario y petición.
   - `RoutePermissionRefresherTest`: la misma `name` con distinto `guard_name` no se pisa.
8. `vendor/bin/phpunit`: todo en verde, incluidos los tests que ya existen (`ServiceProviderCompatibilityTest`).
9. Documentación (en/es en paralelo):
   - `documentacion/doc-{en,es}/04-reference/02-middleware.md`: nuevas opciones, `on_unresolved` y la advertencia del fail-open.
   - `documentacion/doc-{en,es}/02-configuration/`: la sección `permissions.authorization`.
   - `documentacion/doc-{en,es}/03-usage/03-edge-and-extreme-scenarios.md`: el caso de los canales / app-context.
   - `docs/permissions-public-api.md`: el Gate y `EffectivePermissionGate`.
   - Notas de versión v2.6.0 (`docs/release-notes-channel-authorization.md`), incluyendo:
     **«Si usas prefijos de ruta que no son el nombre del guard, tu autorización estaba desactivada»**.
10. Commit, merge a `main`, tag `v2.6.0`, push (y Packagist se actualiza solo con el webhook).
11. Borrar este `PLAN-channel-authorization.md`, o moverlo a `docs/` si se quiere conservar.

---

## 5. Pasos — app (`D:\www\kwikvet`), después de publicar v2.6.0

> **Importante, el orden:** en cuanto `on_unresolved=deny` y el Gate estén activos, el sistema rechaza
> todo lo que no esté concedido. En producción hay que **sincronizar y asignar los permisos antes de
> activar la configuración**, o nadie podrá entrar (tampoco a `/permissions` ni a `/user-profile`).

1. `composer require ronu/rest-generic-class:^2.6` (rama nueva desde `test-admin-default` o `master`).
2. Publicar o rellenar `config/rest-generic-class.php`:
   ```php
   'authorization' => [
       'route_prefixes' => ['admin', 'client', 'mobile'],
       'naming'         => 'route_name',
       'on_unresolved'  => 'deny',
       'register_gate'  => true,
       'gate_guard'     => 'api',
   ],
   ```
3. Comprobar con `--dry-run` antes de escribir nada:
   `php artisan permissions:sync-from-routes --guard=api --dry-run --dump`
   → se esperan unos 1.336 permisos (unos 445 por canal) con nombres `admin.mod_*.*.*`, `client.…` y `mobile.…`.
4. Sincronizar: `php artisan permissions:sync-from-routes --guard=api`.
5. Asignar a los roles según D5, con `roles:assign-permissions` (soporta `--prefix`). Por ejemplo:
   `php artisan roles:assign-permissions Administrator --guard=api --prefix=admin.`
6. `php artisan permission:cache-reset`.
7. **Test de regresión** (en `tests/Feature`; recordar que `tests/` no está en git, ver la memoria
   del proyecto): `Client` → `GET /admin/mod_security/users` = 403; `Administrator` → 200;
   `Client` → `GET /client/...` concedido = 200.
8. Repetir la prueba manual (script `probe.php`, que emite un token real y lanza la petición dentro del proceso):
   las tres filas de la tabla §1.1 deben dar **403**.
9. Corregir `.agents/security/authorization.md`: hoy dice que la base de datos vacía devuelve 403
   (era falso con v2.5.0). Documentar la configuración `authorization` y el orden de despliegue.
   Revisar también `.agents/architecture/channels.md` (todavía menciona el canal `site`).

---

## 6. Criterios de terminado

- [x] Biblioteca: phpunit en verde, cubriendo los modos legacy y canales (255 tests, 662 assertions).
- [x] Biblioteca: valores por defecto y flujo legacy cubiertos; correcciones de versión y rutas sin controlador documentadas (no se afirma identidad absoluta con v2.5.0).
- [x] Biblioteca: documentación en/es y notas de versión incluidas en la entrega v2.6.0; publicación mediante tag `v2.6.0`.
- [ ] kwikvet: la sincronización genera los nombres `<canal>.<módulo>.<entidad>.<acción>` sin choques entre canales.
- [ ] kwikvet: `Client` recibe 403 en `/admin/...`; `Administrator` recibe 200.
- [ ] kwikvet: `GET /admin/permissions` devuelve el catálogo comprimido real de cada usuario.
- [ ] kwikvet: `.agents/security/authorization.md` corregido.

---

## 7. Fase 2 — correcciones tras validar v2.6.0 en kwikvet

> Estado: **PUBLICADA EN v2.7.0** (merge de `fix/authorization-phase-2` en `main`, tag `v2.7.0`).
> Validación: 275 tests, 730 assertions, sin fallos ni errores; permanecen los 2 avisos previos de `BaseModel`.
> §7.5 (kwikvet) no ejecutada.
>
> Detectadas el 2026-10-09 al activar v2.6.0 en kwikvet con
> `route_prefixes=admin,client,mobile`, `naming=route_name`, `on_unresolved=deny` y `register_gate=true`.
> La validación (sincronización + asignación + peticiones reales dentro de una transacción que se deshizo
> al terminar) confirmó que **la autorización funciona**: Client → 403 en `/admin/...`, Administrator → 200.
> Los puntos siguientes son lo que quedó por corregir.

### 7.1 [Corrección — v2.6.1] El modo legacy cambia los nombres de los permisos

**Problema.** `RouteMetaResolver::resolveModuleFromUri()` acepta ahora el nombre del módulo en
`snake_case` (`mod_security` ↔ `ModSecurity`) **también con `route_prefixes = null`**. Un proyecto
legacy con rutas `api/mod_security/...` pasaría de exigir `users.index` (v2.5.0) a exigir
`mod_security.users.index` (v2.6.0), y sus asignaciones a roles dejarían de servir. Contradice
§2.6 («los valores por defecto = comportamiento de v2.5.0»).

**Arreglo** (`src/Core/Resolvers/RouteMetaResolver.php`): aplicar el `snake_case` solo en el modo con
prefijos. Se añade un parámetro opcional para no romper a quien ya extienda el método:

```php
protected function resolveModuleFromUri(string $uri, string $guard, array $modules, bool $matchSnake = false): ?string
{
    // ...
    if ($candidate === $module || ($matchSnake && $candidate === Str::snake($module))) {
        return $candidate;
    }
}
```

```php
// resolveFromRoute()
$matchSnake = ($cfg['route_prefixes'] ?? null) !== null;
$module = $this->resolveModuleFromUri($uri, $prefix, $modules, $matchSnake) ?: $this->defaultModule;
```

Revisar también la condición legacy de v2.5.0, que exigía `count($parts) > 2`: con `route_prefixes = null`
debe dar exactamente el mismo resultado que antes.

**Diferencia de implementación:** la firma protegida de tres parámetros se conserva.
La propuesta anterior de añadir el parámetro opcional al método heredable provoca un error fatal
en PHP para subclases que mantienen tres argumentos (verificado con una prueba de firma).
`$matchSnake=false` se aplica en un helper privado; el hook original se ejecuta en ambos modos
y su contexto se restaura con `finally`. Hay pruebas de herencia y restauración tras excepciones.
La equivalencia con v2.5.0 se refiere a la resolución de módulos/nombres legacy de esta sección;
se conservan las correcciones de versión y rutas sin controlador de v2.6.0.

**Tests** (`tests/Unit/RouteMetaResolverScopeTest.php`):
- legacy (`route_prefixes=null`), `api/mod_security/users` con el módulo `ModSecurity` → `users.index`
  (mismo nombre que v2.5.0, sin módulo).
- legacy, `api/ModSecurity/users` → `ModSecurity.users.index` (la coincidencia exacta sigue funcionando).
- prefijos `['admin']`, `admin/mod_security/users` → el módulo es `mod_security`.

**Notas de versión:** v2.6.1 corrige un cambio de nombres no intencionado en el modo legacy. Avisar a
quien ya sincronizó permisos con v2.6.0 en modo legacy de que debe volver a sincronizarlos.

### 7.2 [Nueva opción — v2.7.0] Permisos globales marcados en la ruta (`explicit_globals`)

> **Implementada en v2.7.0** según este diseño; sustituye a la lista de patrones `global_permissions` del borrador previo.

**Problema.** `RoutePermissionRefresher` marca `restrict = false` (global: lo tiene cualquier usuario
autenticado si `register_gate=true`) toda ruta cuyo módulo no se resuelve. En kwikvet eso incluye
`*.auth.permissions`, `*.auth.user-profile` y `*.auth.modules`, que es lo deseado, pero también **cualquier
ruta futura que se registre fuera de un módulo**: queda abierta a todos los usuarios autenticados sin
que nadie lo decida ni se note. Es un fallo que abre en vez de cerrar, justo lo que v2.6.0 venía a eliminar.

**Decisión (2026-10-09).** Se descartó una lista de patrones en la configuración (`global_permissions`).
Lo global se declara **en la propia ruta**, como atributo de la acción, junto al código que lo justifica:

```php
Route::group(['prefix' => '/', 'as' => 'admin.', 'permission_global' => true], function () {
    Route::get('/permissions', ...)->name('auth.permissions');
    Route::get('/user-profile', ...)->name('auth.user-profile');
});
```

- **Atributo de la acción, NO `->defaults()`.** Laravel convierte los `defaults` en parámetros de la ruta,
  así que llegarían a los controladores como un argumento extra. Los atributos de un `Route::group()` se
  copian a la acción de cada ruta (es lo mismo que ya se hace con `version`, que el resolver lee con
  `Arr::get($route->getAction(), 'version')`).
- Para una sola ruta fuera de un grupo: `Route::get(...)->setAction([...$r->getAction(), 'permission_global' => true])`,
  o meterla en un grupo. Documentar las dos formas.

**Arreglo.**

`config/rest-generic-class.php` → `permissions.authorization`:

```php
// false → comportamiento actual (global = ruta sin módulo).
// true  → SOLO las rutas con el atributo de acción 'permission_global' => true son globales;
//         todo lo demás queda restringido (falla cerrando).
'explicit_globals' => env('REST_AUTHZ_EXPLICIT_GLOBALS', false),
```

`src/Core/Resolvers/RouteMetaResolver.php`: exponer el dato en `RouteMeta` para que el refresher no tenga
que volver a leer la ruta. Añadir al final del constructor de `RouteMeta` un parámetro **opcional**
`public readonly bool $global = false` (opcional y al final, para no romper a quien construya `RouteMeta`)
y rellenarlo en `resolveFromRoute()` con `(bool) Arr::get($route->getAction(), 'permission_global', false)`.

`src/Core/Support/Permissions/RoutePermissionRefresher.php`:

```php
$explicit = (bool) ($cfg['explicit_globals'] ?? false);
// ...
'restrict' => $explicit
    ? !$meta->global                              // solo lo marcado es global
    : $meta->module !== $this->generalModule,     // legacy
```

- Al volver a sincronizar se actualiza `restrict` en las filas existentes (el refresher ya hace `fill()->save()`).
- `UserPermissionReader` no cambia: sigue sumando los `notRestricted()` cuando `register_gate=true`.
- `SpatieAuthorize` no cambia: un permiso global se comprueba igual que cualquier otro; lo único que
  cambia es quién lo tiene (todos los usuarios autenticados, a través del Gate).

**Tests** (`tests/Unit/RoutePermissionRefresherTest.php` y `RouteMetaResolverScopeTest.php`):
- `explicit_globals=false` → `restrict` igual que en v2.6.0 (la ruta sin módulo es global).
- `explicit_globals=true`:
  - ruta sin módulo **con** `'permission_global' => true` en el grupo → `restrict=false`.
  - ruta sin módulo **sin** el atributo → `restrict=true` (el caso que hoy falla abriendo).
  - ruta de un módulo con el atributo → `restrict=false` (el atributo manda sobre el módulo).
- `RouteMeta::$global` refleja el atributo, y `new RouteMeta(...)` sin el parámetro nuevo sigue funcionando.
- El atributo no llega como parámetro a la ruta (`$route->parameters()` no lo contiene).

**Documentación:** sección `permissions.authorization` (en/es) con el ejemplo del grupo y la advertencia
sobre `->defaults()`; `docs/permissions-public-api.md` (qué significa «global» y cómo lo usa el Gate); la
variable `REST_AUTHZ_EXPLICIT_GLOBALS` en `01-env-vars.md`.

### 7.3 [Nueva opción — v2.7.0] Catálogo solo con rutas protegidas (`catalog_middleware`)

**Problema.** En modo `route_name`, el catálogo incluye las rutas públicas de cada canal
(`*.auth.login`, `*.auth.refresh`, `*.auth.logout`, `*.auth.register`, `*.root`, `*.auth.google.login.*`).
No hacen daño, porque esas rutas no pasan por el middleware, pero ensucian el catálogo, aparecen en
`GET /permissions` y en la pantalla de asignación de roles, y alguien podría asignarlas creyendo que
controlan algo. En kwikvet son 22 de los 2.072 permisos.

**Arreglo.** Filtrar en el **refresher** (no en el resolver): si el middleware se está ejecutando, la ruta
ya está protegida, así que el filtro solo tiene sentido al generar el catálogo.

```php
// null → todas las rutas en el ámbito (actual).
// array → solo las rutas cuyo middleware empieza por alguno de estos valores (alias o FQCN).
'catalog_middleware' => env('REST_AUTHZ_CATALOG_MIDDLEWARE') === null ? null : array_values(array_filter(array_map(
    'trim', explode(',', env('REST_AUTHZ_CATALOG_MIDDLEWARE', ''))
), static fn ($m) => $m !== '')),
```

```php
// RoutePermissionRefresher::refresh(), antes de resolveFromRoute()
$markers = $cfg['catalog_middleware'] ?? null;
if ($markers !== null && !collect($route->gatherMiddleware())->contains(
    static fn ($mw) => is_string($mw) && Str::startsWith($mw, $markers)
)) {
    continue;
}
```

- `gatherMiddleware()` devuelve los alias tal como se escribieron (`maybe.permission:api`), así que
  `'maybe.permission'` funciona como prefijo. Aceptar también el FQCN
  (`Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize`) para quien registre el middleware por clase.
- Este filtro no elimina permisos ya existentes en la base de datos. Documentar que las filas sobrantes
  hay que borrarlas a mano, o añadir una opción `--prune` como mejora aparte (fuera del alcance de esta fase).

**Tests** (`tests/Unit/RoutePermissionRefresherTest.php`):
- `catalog_middleware=null` → incluye una ruta sin el middleware (como v2.6.0).
- `['maybe.permission']` → incluye la ruta con `maybe.permission:api` y excluye la ruta sin él.
- un middleware de tipo closure u objeto en la lista no provoca error.

### 7.4 Pasos

**Decisión de publicación:** §7.1–§7.3 se publican juntas en **v2.7.0** (sin tag v2.6.1 independiente).

1. Rama `fix/authorization-phase-2` desde `main` (v2.6.0).
2. §7.1 + tests, commit, tag **v2.6.1** y push. (Si nadie más usa v2.6.0 todavía, se puede juntar con
   §7.2–7.3 en una sola v2.7.0; decidir antes de empezar.)
3. §7.2 (`explicit_globals`) + §7.3 + tests + documentación (en/es) + `docs/release-notes-channel-authorization.md`
   (sección v2.7.0). Commit, tag **v2.7.0** y push.
4. `vendor/bin/phpunit`: todo en verde. Los 2 avisos de PHP obsoleto de `BaseModel` (parámetros
   nullable implícitos) no son de esta fase; corregirlos aparte si se quiere.
5. Comprobar que la nueva versión aparece en Packagist.
6. Actualizar el estado de este plan y avisar para planificar la parte de kwikvet.

### 7.5 Lo que queda para kwikvet (se planificará cuando la biblioteca esté publicada)

Solo para tenerlo en cuenta. No se ejecuta desde aquí.

- **Login por canal (`AuthController`):** hoy cualquier usuario con usuario y contraseña válidos obtiene un
  token de cualquier canal (comprobado: `login()` no mira roles ni canal). Propuesta: solo puede entrar en
  un canal quien tenga al menos un permiso restringido `<canal>.*`. Aplicarlo en `login`, `refresh` y el
  login con Google. No es cosa de la biblioteca.
- `composer.json`: `^2.3.2` → `^2.7` (`composer.lock` está en `.gitignore`, así que la restricción del
  `composer.json` es lo único que fija la versión).
- Configuración: `route_prefixes`, `naming`, `on_unresolved`, `register_gate`, `gate_guard`,
  `explicit_globals=true` (más `'permission_global' => true` en el grupo de `auth.*` de cada
  `routes/{admin,client,mobile}.php`) y
  `catalog_middleware=maybe.permission`.
- Sincronizar, asignar los permisos a los roles (D5), tests de regresión, y corregir
  `.agents/security/authorization.md` y `.agents/architecture/channels.md`.
- Revisar la §5 de este plan: ya no se esperan 1.336 permisos, sino unos 2.050 (sin las rutas públicas).

### 7.6 Criterios de terminado — fase 2

- [x] Modo legacy: mismos nombres que v2.5.0 para `api/mod_x/...` (test).
- [x] `explicit_globals`: solo las rutas marcadas con `permission_global` quedan con `restrict=false` (test).
- [x] `catalog_middleware`: las rutas públicas no entran en el catálogo (test).
- [x] phpunit en verde; documentación en/es y notas de versión actualizadas para el alcance implementado.
- [ ] Versiones publicadas en Packagist.
