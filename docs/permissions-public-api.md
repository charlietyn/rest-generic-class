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
