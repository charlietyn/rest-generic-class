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
| Spatie (models/pivots/registrar/cache) | Compatible | Se sigue usando; middleware no toca DB, usa cache/registrar |
| Contratos propios (`ProvidesRoles`, `ProvidesRolePermissions`) | Compatible | Sin cambios; enforcement en `UserRolesResolver` |
| Rutas / `refreshPermissions` | Compatible | Logica movida a `RoutePermissionRefresher`; misma salida |
| Payload autenticado | Compatible | `permissionsPayload()` identico; delega en builder |
| Middleware `SpatieAuthorize` | Compatible | `handle()` intacto byte a byte |
| Compresion de permisos | Compatible | `effectivePermissionsCompressed()` identico |

Nota sobre `strict mode`: el docblock de `SpatieAuthorize` menciona un modo
estricto (abortar si un permiso no existe en cache). Ese comportamiento **no**
esta implementado en `handle()` hoy, y este refactor lo mantuvo asi para no
alterar el comportamiento HTTP. Si se decide implementarlo, debe ir en un corte
posterior con su propia cobertura.

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
