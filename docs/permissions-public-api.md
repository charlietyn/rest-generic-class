# API publica de permisos

Cierre de los cortes 18-23 del refactor de permisos. Este documento fija que es
contrato estable, que es soporte interno no estable, y como migrar.

## 1. Contratos

### Obligatorios (el consumidor debe cumplirlos)

Para que un modelo de usuario resuelva roles y permisos via este paquete:

- `Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles`
  - `provideRoles(): Collection` — el trait `HasReadableUserPermissions` ya provee
    una implementacion por defecto basada en la relacion de roles configurable.
- `Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions`
  - `provideRolePermissions(): Collection` — el trait `HasReadableRolePermissions`
    ya provee una implementacion por defecto (`enabled_permissions`).

Si el modelo de usuario declara `ProvidesRoles` pero sus roles no implementan
`ProvidesRolePermissions`, `UserRolesResolver` lanza
`RolesContractViolationException`. Esta es la frontera de contratos.

### Opcionales (puntos de extension)

- `PermissionCompressorContract` — resuelto por contenedor; permite sustituir el
  algoritmo de compresion de wildcards.
- Constante de modelo `ROLES_RELATION` o config
  `rest-generic-class.permissions.roles_relation` — nombre de la relacion de roles
  (default `roles`). Soporta cardinalidad 1:N y N:N.
- `permission.*` de Spatie (models, pivot_models, table_names, column_names) — se
  siguen leyendo tal cual; no se reemplazan.

## 2. Superficie publica estable

Traits (capa compatible para consumidores):

- `HasReadableUserPermissions`: `directPermissions`, `rolePermissions`,
  `enabled_permissions`, `provideRoles`, `getEnabledPermissionsViaRoles`,
  `effectivePermissions`, `effectivePermissionsCompressed`, `permissionsPayload`,
  `permissionsFiltered`.
- `HasReadableRolePermissions`: `provideRolePermissions`, `allPermissions`,
  `permissionsFiltered`.
- `HasPermissionsService`: `assignPermissionToRoles`, `assignPermissionToUsers`,
  `getPermissionsByRoles(+Compressed)`, `getPermissionsByUsers(+Compressed)`,
  `aggregate`, `refreshPermissions`.
- `HasPermissionsController`: entry-points HTTP (sin cambios).

Middleware:

- `SpatieAuthorize::handle()` — comportamiento HTTP y de resolucion intacto.

Las firmas y resultados de estos metodos se mantienen. La logica interna se movio
a colaboradores (ver seccion 3) sin cambiar la fachada.

## 3. Soporte interno (NO estable)

No dependas directamente de estas clases; pueden cambiar de firma o ubicacion:

| Clase | Responsabilidad | Extraida de |
|---|---|---|
| `PermissionPayloadBuilder` | Payload plano/comprimido desde flags de request | `HasReadableUserPermissions` |
| `PermissionFilter` | Filtrado por guard/module/entity | ambos traits de lectura |
| `PermissionUniverseResolver` | Universo de permisos base de compresion | `HasReadableUserPermissions` |
| `UserPermissionReader` | Permisos efectivos (directos ∪ via roles) | `HasReadableUserPermissions` |
| `RolePermissionReader` | Permisos de rol + globales no restringidos | `HasReadableRolePermissions` |
| `UserRolesResolver` | Roles de un usuario, con enforcement de contrato | traits/servicios |
| `PermissionListNormalizer` | Normalizacion de listas (coma/array/dedupe) | `HasPermissionsService` |
| `RoleInputResolver` | Roles de entrada por name/id, guard-aware | `HasPermissionsService` |
| `TargetPermissionResolver` | Permisos destino multi-fuente | `HasPermissionsService` |
| `RolePermissionAssignmentService` | ADD/SYNC/REVOKE + armado de filas + modo | `HasPermissionsService` |
| `RoutePermissionRefresher` | Sincroniza permisos desde rutas | `HasPermissionsService` |
| `RequiredPermissionResolver` | Permiso requerido por request | `SpatieAuthorize` |
| `PermissionDecisionEvaluator` | Evaluacion `any`/`all` | `SpatieAuthorize` |

## 4. Matriz de compatibilidad

| Dimension | Estado | Nota |
|---|---|---|
| Spatie (models/pivots/registrar/cache) | Compatible | Se sigue usando; la comprobación usa user->can(); el Gate opcional carga y cachea permisos por petición |
| Contratos propios (`ProvidesRoles`, `ProvidesRolePermissions`) | Compatible | Sin cambios; enforcement en `UserRolesResolver` |
| Rutas / `refreshPermissions` | Compatible | Logica movida a `RoutePermissionRefresher`; misma salida |
| Payload autenticado | Compatible | `permissionsPayload()` identico; delega en builder |
| Middleware `SpatieAuthorize` | Compatible | v2.6.0: configuración opcional por canales; véase sección 7 |
| Compresion de permisos | Compatible | `effectivePermissionsCompressed()` identico |

La antigua opción `permission_map.strict` no se interpreta en `handle()`.
Desde v2.6.0, `permissions.authorization.on_unresolved=deny` rechaza rutas
sin permiso resoluble; un permiso resuelto que el usuario no tiene devuelve 403.

## 5. Guia de migracion para consumidores

En la mayoria de los casos **no hay que hacer nada**: los traits, contratos y el
middleware conservan su API.

1. Sigue consumiendo via traits (`HasReadable*`, `HasPermissions*`) y el
   middleware. No importes los colaboradores de la seccion 3.
2. Si extendias `SpatieAuthorize` y sobreescribias `resolveRequiredPermissions`,
   `normalizePermissions`, `mapRouteNameToPermission`, `mapActionToPermission`,
   `mapHttpVerbToPermission`, `userCanAny` o `userCanAll`: siguen existiendo como
   `protected` y ahora delegan en los colaboradores. Tu override sigue funcionando.
   Para logica nueva, prefiere inyectar/decorar `RequiredPermissionResolver` o
   `PermissionDecisionEvaluator`.
3. Si dependias de metodos privados internos (p.ej. `permissionCompressionUniverse`,
   `resolveRoles`, `resolveTargetPermissions`): eran privados y se movieron. Usa la
   fachada publica equivalente o el colaborador correspondiente.
4. Para sustituir la compresion, sigue registrando tu implementacion de
   `PermissionCompressorContract` en el contenedor.

## 6. Cobertura

Tests unitarios directos sobre cada colaborador (sin depender de Spatie ni de una
app Laravel completa; usando SQLite en memoria donde hay consulta):

- `PermissionUniverseResolverTest`, `UserPermissionReaderTest`,
  `RolePermissionReaderTest`, `TargetPermissionResolverTest`,
  `PermissionAssignmentSupportTest`, `AuthorizationSupportTest`,
  ademas de los previos `PermissionFilterTest`, `PermissionPayloadBuilderTest`,
  `AuthenticatedPermissionsPayloadTest`, `UserRolesResolutionTest`,
  `PermissionCompressorTest`.

## 7. Autorización por canales y Gate opcional (v2.6.0)

Configura `rest-generic-class.permissions.authorization`; consulta las
[notas de versión](release-notes-channel-authorization.md) para activar los cambios en orden.

`Ronu\RestGenericClass\Core\Support\Authorization\EffectivePermissionGate` ofrece
`allows(object $user, string $ability): ?bool` y `clear(): void`. Devuelve `true` para
nombres efectivos exactos y `null` cuando no concede, permitiendo continuar con policies
y otros Gates. No interpreta comodines de presentación ni concede por nombre de rol.
Los modelos que exponen `checkPermissionTo()` quedan a cargo de Spatie.

El provider registra el servicio como `scoped`; solo instala `Gate::before` cuando
`register_gate=true`. Una `WeakMap` aísla objetos usuario, incluso con el mismo ID en
modelos/conexiones diferentes, y separa los guards. Se carga una vez por objeto/guard
por petición (no necesariamente una única consulta SQL), se limpia con `RequestHandled`
y se descarta al reiniciar el scope en workers. Un cambio de permisos dentro de una
petición requiere `clear()` si se necesita una comprobación inmediata.

Al activar `register_gate`, el lector compartido incorpora permisos `notRestricted()`
al conjunto efectivo, incluso para usuarios sin roles; mantiene el filtro de guard
y deduplica por ID. Sin activarlo, conserva la unión anterior. Los modelos de permisos
deben proporcionar el scope `notRestricted`, igual que para la lectura por roles.
La implementación del paquete incluye restricciones `false`, `0` y `null`.
Los permisos globales pertenecen a cualquier usuario del guard, incluidos otros canales.

`RouteMetaResolver::resolve()` delega en `resolveFromRoute()` y sigue siendo extensible;
`matchedPrefix()` y `passesFilters()` son puntos de extensión. `handle()` utiliza ese
resolver; los helpers de `RequiredPermissionResolver` siguen disponibles para subclases,
pero sus overrides no cambian por sí solos la resolución canónica del middleware.
La sincronización identifica permisos por `(name, guard_name)` y su dry-run no modifica
el catálogo ni invalida la caché.

## 8. Fase 2: globales explícitos y selección del catálogo

`permissions.authorization.explicit_globals` controla `restrict` al sincronizar:
`false` conserva la regla anterior (sin módulo reconocido = global); `true` marca como globales
solo las rutas cuya acción lleva `'permission_global' => true` y restringe todas las demás.
El atributo se declara en un `Route::group()` o con `setAction()`, nunca con `->defaults()`
(se convertiría en parámetro del controlador). Puede declarar global una ruta dentro de un módulo.
`RouteMeta` expone el dato en la propiedad opcional `public readonly bool $global = false`. Al resincronizar se
actualizan las filas existentes seleccionadas, manteniendo su ID y sus asignaciones.
`UserPermissionReader` y `EffectivePermissionGate` no cambian: continúan usando los
`notRestricted()` persistidos cuando `register_gate=true`, con el filtro de guard.
Esta opción no es una lista de permisos del usuario ni un filtro de lectura del Gate.

`permissions.authorization.catalog_middleware` selecciona rutas solo en
`RoutePermissionRefresher::refresh()`, antes de llamar al resolver. `null` incluye todas
las rutas del ámbito; un array exige una coincidencia de prefijo de string entre los
marcadores y `gatherMiddleware()`; `[]` no selecciona ninguna. Acepta alias como
`maybe.permission` y FQCN como `Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize`,
incluidos parámetros `:api`, sin expandir alias/grupos. Ignora closures y objetos.
El resolver y el middleware de autorización no aplican este filtro.

Las variables son `REST_AUTHZ_EXPLICIT_GLOBALS` (booleana) y `REST_AUTHZ_CATALOG_MIDDLEWARE`
(separada por comas; sin definir significa `null`, vacía significa `[]`).
Después de actualizar la caché de configuración, sincroniza y revisa las asignaciones.
No hay borrado ni `--prune`: las filas excluidas no se actualizan, incluso si previamente
eran globales. Elimina los permisos obsoletos manualmente tras revisar sus asignaciones.

La corrección legacy conserva la firma protegida de tres argumentos de
`resolveModuleFromUri()`. El indicador opcional `$matchSnake=false` se coloca en un helper
privado: añadirlo a la firma heredable causaría un error fatal en subclases que mantienen
tres argumentos. El hook existente se ejecuta en ambos modos, con restauración de su
contexto mediante `finally`, también ante excepciones o llamadas anidadas.
Con prefijos `null` aplica la coincidencia exacta y `count($parts)>2` de v2.5.0; con
prefijos explícitos admite snake_case. Se mantienen las correcciones de versión y
rutas sin controlador introducidas en v2.6.0, sin alterar las firmas públicas.

## 9. Módulos (`HasPermissionsController::modules()`)

`GET /<canal>/modules` devuelve los nombres de los módulos de nwidart más el módulo general `--site--`.
`?enabled=true|false` filtra por estado (ausente, vacío o no reconocido: todos) y `?format=uri` devuelve
el segmento de URI (`ModSecurity` → `mod_security`), el valor que guarda `permissions.module` con
`naming=route_name`. Sin `nwidart/laravel-modules` la respuesta es `['--site--']`. `getModules(?bool $type)`
es estático y lee los módulos del hook `protected static moduleCollection(): Collection`.

## 10. Acciones por capacidad, notación genérica y catálogo en lote (v2.8.0)

### 10.1 Vocabulario

| Término | Qué es | Dónde vive |
| --- | --- | --- |
| **Acción** | Algo que se puede hacer: `admin.crm.clients.pets.read`. El **catálogo** es el conjunto de acciones válidas, derivado de las rutas. | tabla `permissions` (Spatie) |
| **Permiso** | La relación entre una acción y quien puede hacerla (rol o usuario). | `role_has_permissions`, `model_has_permissions` |

El código conserva los nombres de Spatie (`Permission`, `permissions:sync-from-routes`).

### 10.2 Atributos de acción de ruta (solo `naming=route_name`)

| Atributo | Tipo | Efecto |
| --- | --- | --- |
| `permission_ability` | `string \| string[]` | Sustituye el **último segmento** del nombre de la ruta. Una lista son **alternativas**: basta una (`canAny`). |
| `permission_requires` | `string[]` | Acciones **adicionales**, todas exigidas (`canAll`), relativas al nombre de la ruta. |

Gramática de `permission_requires`, relativa al nombre de la ruta (`admin.crm.clients.pets.list`):
`^^show` → `admin.crm.clients.show`, `^read` → `admin.crm.clients.pets.read`, `=admin.x.y` → `admin.x.y`.
Cada `^` quita un segmento del final. Un valor que se queda sin segmentos, sin `^` ni `=`, o un
`permission_ability` vacío, con `.` o con `*`, hace la ruta **no resoluble** (403 con `on_unresolved=deny`).
Con `naming=derived` los atributos se ignoran y se registra un único `warning`.

Los nombres de ruta no cambian (siguen siendo únicos; `route:cache` y `route()` intactos). Como
`permission_global`, se declaran en un `Route::group()` o con `setAction()`, nunca con `->defaults()`.
Laravel fusiona los atributos de grupos anidados con `array_merge_recursive`: decláralos en el grupo
más interno para no sumar alternativas por accidente.

`RouteMeta` incorpora `abilities` (alternativas absolutas; sin atributo, `[canonicalName]`) y `requires`
(absolutas; sin atributo, `[]`). `canonicalName` es la primera alternativa. Ambos son parámetros
opcionales al final del constructor: las llamadas existentes siguen funcionando.

`SpatieAuthorize` exige `canAny($abilities) && canAll($requires)`. Con `app.debug` el 403 nombra lo que
falta (`Forbidden: missing one of …` / `Forbidden: missing …`); con una sola alternativa el mensaje es el
de v2.7 (`Forbidden: admin.users.index`). Si autoriza, deja el contexto en el atributo de request
`SpatieAuthorize::CONTEXT_ATTRIBUTE` (`guard`, `action`, `resource`).

Ejemplo completo: relación O2M `clients → pets` (canal `admin`, módulo `crm`).

```php
Route::group(['prefix' => 'clients/{id}/pets', 'as' => 'clients.pets.', 'permission_requires' => ['^^show']], function () {
    Route::group(['permission_ability' => ['read', 'manage']], function () {
        Route::get('/', [PetsController::class, 'index'])->name('list');
        Route::get('/{pet}', [PetsController::class, 'show'])->name('show');
    });
    Route::group(['permission_ability' => ['add', 'manage']], function () {
        Route::post('/', [PetsController::class, 'store'])->name('create');
        Route::post('/bulk', [PetsController::class, 'bulkStore'])->name('bulk-create');
    });
    Route::group(['permission_ability' => ['update', 'manage']], function () {
        Route::put('/{pet}', [PetsController::class, 'update'])->name('update');
        Route::put('/bulk', [PetsController::class, 'bulkUpdate'])->name('bulk-update');
    });
    Route::group(['permission_ability' => ['delete', 'manage']], function () {
        Route::delete('/{pet}', [PetsController::class, 'destroy'])->name('delete');
        Route::delete('/bulk', [PetsController::class, 'bulkDestroy'])->name('bulk-delete');
    });
});
```

| Ruta | Se exige |
| --- | --- |
| `admin.crm.clients.pets.list` / `show` | (`…clients.pets.read` **o** `…clients.pets.manage`) **y** `…clients.show` |
| `admin.crm.clients.pets.create` / `bulk-create` | (`…add` o `…manage`) y `…clients.show` |
| `admin.crm.clients.pets.update` / `bulk-update` | (`…update` o `…manage`) y `…clients.show` |
| `admin.crm.clients.pets.delete` / `bulk-delete` | (`…delete` o `…manage`) y `…clients.show` |

`manage` no es una ruta: es una alternativa presente en todas, así que «todo sobre la relación» es una
sola acción concedible. El mismo mecanismo colapsa variantes de una entidad (`bulk-update` → `update`).

### 10.3 Catálogo por capacidad y en lote

`RoutePermissionRefresher::refresh($guard, $dry, bool $prune = false)` registra **cada alternativa** como
acción: las 8 rutas del ejemplo dan 5 acciones (`read`, `add`, `update`, `delete`, `manage`).
`permission_requires` no crea acciones. Se deduplica por nombre antes de escribir; `route` y `action`
salen de la primera ruta, y las filas devueltas (`--dump`) listan todas las rutas de cada acción. Una
acción compartida es global solo si lo son todas sus rutas.

Las filas existentes del guard se leen una vez; se insertan en lotes de 500 y solo se actualizan las que
cambian, en una transacción y con el constructor de consultas (sin eventos de modelo). Una segunda
sincronización no escribe nada. La caché de permisos se limpia una vez al final de cada sincronización
real; `dry-run` no escribe ni limpia la caché.

Con `$prune`, se borran las acciones del guard que ya no produce ninguna ruta **solo si** nadie tiene
permiso sobre ellas (`role_has_permissions` ni `model_has_permissions`); las demás se informan como
huérfanas. `report()` devuelve `created`, `updated`, `unchanged`, `pruned`, `orphans` y `dry_run`. En el
trait: `refreshPermissions($guard, $dry, $prune)` y `permissionSyncReport()`. El comando generado
`permissions:sync-from-routes` vive en el módulo de seguridad de cada proyecto; para exponer `--prune`:

```php
protected $signature = 'permissions:sync-from-routes {--guard=api} {--dry-run} {--dump} {--prune}';

$rows = $this->refreshPermissions($guard, $dry, (bool) $this->option('prune'));
$report = $this->permissionSyncReport();
foreach ($report['orphans'] as $name) {
    $this->components->warn("Kept (still granted): $name");
}
```

### 10.4 Notación genérica para conceder permisos

Un **patrón** es un nombre de acción cuyo último segmento puede ser `*`: `admin.*` (canal),
`admin.crm.*` (módulo), `admin.crm.clients.*` (entidad **y sus relaciones**), `admin.crm.clients.pets.*`
(relación) y `*` (todo el guard). El `*` casa segmentos completos: `admin.crm.clients.*` no casa
`admin.crm.clients-notes.read`. Un `*` en otra posición lanza `InvalidArgumentException`.

`TargetPermissionResolver` acepta patrones en `--perms` y en los ficheros de rol (`--from`) y los expande
contra el catálogo del guard **al conceder**: el permiso se guarda como filas concretas acción↔rol, la
comprobación por petición sigue siendo exacta y Spatie no necesita `enable_wildcard_permission`. Una
acción creada después no queda concedida sola: vuelve a aplicar el fichero del rol (modo `SYNC`). Un
patrón que no casa nada no concede nada (nunca cae en «todos los permisos del guard»).

```json
["admin.crm.clients.*", "admin.billing.invoices.index", "admin.billing.invoices.show"]
```

`--prefix` respeta ahora el límite de segmento (`admin.crm.clients` ya no casa `admin.crm.clients-notes`)
y admite `admin.crm.` o `admin.crm.*`. `--entities` usa `LOWER(col) = LOWER(?)` en vez de `ilike`
(portable a MySQL, SQLite y SQL Server).

`PermissionCompressor` comprime por prefijo jerárquico a cualquier profundidad y elige el patrón más
corto que cubre exactamente el subconjunto: es la misma notación con la que se conceden. El nivel 1
respeta `module_wildcard` y los niveles más profundos `table_wildcard`; los comodines se ordenan por
profundidad y nombre.

### 10.5 Cargas anidadas (`?relations=`)

`permissions.authorization.eager_relations`: `off` (por defecto, como v2.7) o `require_read`. Con
`require_read`, cada salto **a muchos** de la relación pedida exige `<recurso>.<ruta de la relación>.read`
o `….manage`, donde `<recurso>` es el nombre de la acción autorizada sin su último segmento
(`admin.crm.clients.index` → `?relations=pets` exige `admin.crm.clients.pets.read|manage`; `pets.vaccines`
exige además `admin.crm.clients.pets.vaccines.read|manage`). Las relaciones **a uno** (`belongsTo`,
`hasOne`, `morphOne`, `hasOneThrough`, `morphTo`) quedan exentas con `eager_relations_to_one=allow`
(por defecto). Si falta el permiso: 403 con `filtering.strict_relations=true` (por defecto) y se omite la
relación en caso contrario. Se aplica en `list_all`, `get_one`, `show`, jerarquías y rutas de relación,
**antes** de consultar la caché, así que la clave de caché refleja solo las relaciones autorizadas. Solo
las peticiones autorizadas por `SpatieAuthorize` llevan el contexto; sin él no se comprueba nada.
