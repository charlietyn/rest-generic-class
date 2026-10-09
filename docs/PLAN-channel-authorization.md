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
