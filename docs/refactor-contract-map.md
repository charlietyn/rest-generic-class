# Mapa inicial de contrato publico

Este documento fija la primera linea de compatibilidad antes de mover logica interna. No pretende ser definitivo; sirve como control para que el refactor no rompa consumidores actuales.

## API estable de alto riesgo

### `Ronu\RestGenericClass\Core\Services\BaseService`

Tratar como API publica compatible:

- Constructor `__construct(Model|string $modelClass)`.
- Lecturas: `list_all`, `get_one`, `process_query`, `process_all`, `process_pagination`, `show`, `listHierarchy`, `showHierarchy`.
- Escrituras: `create`, `save`, `save_array`, `update`, `update_multiple`.
- Borrado y recuperacion: `destroy`, `destroybyid`, `restore`, `restoreById`, `forceDelete`, `forceDeleteById`.
- Validacion/padres: `get_parents`, `save_parents`, `validate_all`.
- Exportacion: `exportExcel`, `exportPdf`.

Los metodos privados actuales de cache, relaciones, filtros y jerarquia pueden moverse a colaboradores siempre que los metodos anteriores mantengan firma y resultado.

Estado del refactor interno:

- Cache: `CacheCoordinator`.
- Resolucion de relaciones: `RelationResolver`.
- Orquestacion de query: `QueryBuilderPipeline`.
- Filtros `oper`: `OperFilterPipeline`.
- Paginacion: `PaginationCoordinator`.
- Exportacion: `ExportCoordinator`.
- Jerarquia: `HierarchyCoordinator`.
- Lectura/exportacion de relaciones: `RelationReadCoordinator`.
- Mutaciones compartidas de relaciones: `RelationMutationCoordinator`.
- Mutaciones pivot M2M: `PivotMutationCoordinator`.
- Filtros/query de relaciones: `RelationQueryFilter`.
- Existencia en base de datos: `DatabaseExistenceChecker`.
- Soporte de reglas de validacion: `ValidationRuleSupport`.
- Soporte de unicidad de validacion: `UniqueValidationSupport`.
- Helper legacy de unicidad en arrays de update: `UpdateArrayUniqueValidator`.
- Payload autenticado de permisos: `PermissionPayloadBuilder`.
- Filtro compartido de permisos: `PermissionFilter`.

### `Ronu\RestGenericClass\Core\Models\BaseModel`

Tratar como API publica compatible:

- Metodos de identificacion y escenario: `getPrimaryKey`, `getFieldKeyUpdate`, `getScenario`, `setScenario`.
- Soft delete: `getSoftDeleteColumn`.
- Jerarquia: `hasHierarchy`, `hasHierarchyField`, `getHierarchyFieldId`, `hierarchyParent`, `hierarchyChildren`, `isHierarchyRoot`, `getHierarchyAncestors`, `getHierarchyDescendants`.
- Validacion y escritura: `self_validate`, `save_model`, `save_parents`, `validate_all`, `get_parents`, `create_model`, `save_array`, `update_multiple`, `show`, `destroy_model`.
- Relaciones Mongo: `belongsToMongo`, `hasManyMongo`, `hasOneMongo`.

El refactor debe permitir que modelos externos adopten contratos pequenos sin obligarlos siempre a heredar de `BaseModel`.

### Relaciones

Tratar como contrato de consumidor los metodos publicos de `ManagesRelations`:

- `listRelation`
- `showRelation`
- `exportRelationExcel`
- `exportRelationPdf`
- `createRelation`
- `updateRelation`
- `deleteRelation`
- `attachRelation`
- `detachRelation`
- `updatePivotRelation`
- `processRelationPagination`
- `process_pagination`

La logica interna de pivots, filtros y exportacion puede extraerse, pero las respuestas HTTP y errores deben conservarse.

### Validacion

Tratar como contrato las reglas:

- `IdsExistInTable`
- `IdsExistNotDelete`
- `IdsExistWithAnyStatus`
- `IdsExistWithDateRange`
- `IdsWithCustomQuery`
- `UniqueInPivot`
- `UniqueInPivotArray`
- `UniqueCompositeInArray`
- `ArrayCount`

Tambien es contrato el trait `ValidatesExistenceInDatabase` para consumidores que lo usen directamente.

### Permisos

Tratar como contrato:

- `ProvidesRoles`
- `ProvidesRolePermissions`
- `PermissionCompressorContract`
- `UserRolesResolver`
- `PermissionCompressor`
- Traits `HasReadableUserPermissions`, `HasReadableRolePermissions`, `HasPermissionsService`, `HasPermissionsController`.
- Middleware `SpatieAuthorize`.

Spatie debe quedar detras de adaptadores o contratos, sin eliminar compatibilidad actual.

Estado del refactor interno de permisos (soporte no estable, sujeto a cambio):

- Payload autenticado: `PermissionPayloadBuilder`.
- Filtro compartido de permisos: `PermissionFilter`.
- Universo de compresion: `PermissionUniverseResolver`.
- Lectura de permisos efectivos de usuario: `UserPermissionReader`.
- Lectura de permisos de rol + globales no restringidos: `RolePermissionReader`.
- Resolucion de roles de un usuario por contrato: `UserRolesResolver`.
- Compresion de permisos: `PermissionCompressor` (via `PermissionCompressorContract`).
- Normalizacion de listas de permisos/roles/modulos: `PermissionListNormalizer`.
- Resolucion de roles de entrada (name/id, guard-aware): `RoleInputResolver`.
- Resolucion de permisos destino (perms/prefix/from/modules/entities/default-all): `TargetPermissionResolver`.
- Asignacion ADD/SYNC/REVOKE y armado de filas: `RolePermissionAssignmentService`.
- Sincronizacion de permisos desde rutas: `RoutePermissionRefresher`.
- Resolucion de permiso requerido por request (override/route/action/verbo): `RequiredPermissionResolver`.
- Evaluacion `any`/`all` del usuario: `PermissionDecisionEvaluator`.

Estas clases son colaboradores internos. Los consumidores deben depender de los traits/contratos publicos listados arriba, no de estas clases directamente.

## Primer corte recomendado

El primer cambio de codigo debe evitar firmas publicas. La zona mas segura es cache porque hoy esta encapsulada en metodos privados de `BaseService`.

Pasos:

1. Crear un colaborador interno para politica y claves de cache.
2. Hacer que `BaseService` delegue en ese colaborador.
3. Mantener los metodos privados existentes como wrappers o reemplazarlos solo dentro de `BaseService`.
4. Cubrir cache con tests de contrato antes de seguir con relaciones o filtros.

## Segundo corte recomendado

La siguiente zona viable es resolucion de relaciones:

1. Extraer parseo y normalizacion de `relations`.
2. Resolver relaciones permitidas por contrato o por `RELATIONS`.
3. Mantener fallback de auto-deteccion solo cuando `strict_relations` sea falso.
4. Extraer resolucion de modelo relacionado y campos obligatorios para eager loading selectivo.
5. Mantener en `BaseService` la aplicacion de filtros mientras se extrae `QueryBuilderPipeline`.

## Tercer corte recomendado

La orquestacion de consultas puede moverse sin reimplementar filtros:

1. Crear `QueryBuilderPipeline` para coordinar `attr`, `oper`, `relations`, `select`, `orderby` y `pagination`.
2. Mantener `HasDynamicFilter`, `HasDynamicOrderBy` y `applyOperTree` dentro de `BaseService` durante este corte.
3. Pasar callbacks desde `BaseService` al pipeline para preservar semantica exacta.
4. Cubrir el orden de ejecucion y el paso de `oper` decodificado a relaciones anidadas.
5. Extraer la logica real de filtros solo en un corte posterior, porque afecta directamente `whereHas`, limites de recursion y prefijo de tablas.

## Cuarto corte recomendado

El motor `oper` se puede extraer manteniendo `HasDynamicFilter` como responsable de operadores:

1. Crear `OperFilterPipeline` para normalizar `oper`, separar filtros base y filtros de relacion.
2. Mantener `applyFilters` dentro de `BaseService`/`HasDynamicFilter` mediante callback.
3. Mover `whereHas` anidado al nuevo colaborador.
4. Conservar el estado de `currentDepth` y `conditionCount` en `BaseService` hasta que se introduzca un objeto de contexto explicito.
5. Cubrir limites `max_depth` y `max_conditions` con tests directos.

## Quinto corte recomendado

La paginacion puede aislarse sin cambiar la API publica:

1. Crear `PaginationCoordinator` para paginacion normal y cursor pagination.
2. Mantener `BaseService::process_pagination()` como wrapper publico.
3. Mantener `BaseService::pagination()` como wrapper privado mientras existan llamadas internas.
4. Cubrir `pageSize`, `pagesize` e `infinity`.
5. Evitar mezclar este corte con exportacion para que el diff sea pequeno y reversible.

## Sexto corte recomendado

La exportacion puede extraerse manteniendo los paquetes opcionales como dependencias de uso:

1. Crear `ExportCoordinator` para preparar payloads de exportacion.
2. Mantener `BaseService::exportExcel()` y `BaseService::exportPdf()` como wrappers publicos.
3. Extraer `extractExportData`, `resolveExportColumns` y `normalizeExportColumns`.
4. No hacer que los tests unitarios dependan de `maatwebsite/excel` o `barryvdh/laravel-dompdf`; probar solo la logica propia.
5. Dejar las llamadas reales a Excel/PDF dentro de los metodos publicos del coordinador.

## Septimo corte recomendado

La jerarquia puede aislarse despues de paginacion/exportacion:

1. Crear `HierarchyCoordinator` para `listHierarchy()` y `showHierarchy()`.
2. Mantener ambos metodos publicos en `BaseService` como wrappers compatibles.
3. Mover normalizacion de parametros, modos de filtro, ancestros, descendientes, armado de arbol y paginacion de raices.
4. Pasar callbacks para reutilizar `process_query`, `relations`, `list_all` y `show` sin abrir nueva API publica.
5. Cubrir arboles, `children_key`, paginacion de raices, fallback con jerarquia deshabilitada y errores de modo invalido.

## Octavo corte recomendado

`ManagesRelations` debe dividirse por flujos, empezando por lectura:

1. Crear `RelationReadCoordinator` para `listRelation()`, `showRelation()`, exportacion, parseo de parametros y paginacion.
2. Mantener los metodos publicos del trait como wrappers compatibles.
3. Mantener filtros, ordering, resolucion de config y resolucion de padre como callbacks al trait durante este corte.
4. No mezclar todavia mutaciones ni pivots; esos escenarios necesitan un coordinador propio.
5. Cubrir listado, show, paginacion y payload de exportacion sin depender de Excel/PDF reales.

## Noveno corte recomendado

Las mutaciones compartidas pueden extraerse antes de los pivots:

1. Crear `RelationMutationCoordinator` para `createRelation()`, `updateRelation()` y `deleteRelation()`.
2. Mantener `executeMutation()` en el trait como frontera transaccional y pasarlo por callback.
3. Mantener resolucion de config/padre, extraccion de data, deteccion bulk y builders de error como callbacks.
4. Cubrir create single, update single, update bulk con faltantes y delete bulk con faltantes.
5. Dejar `attachRelation()`, `detachRelation()` y `updatePivotRelation()` para `PivotMutationCoordinator`.

## Decimo corte recomendado

Las mutaciones pivot M2M cierran la division principal de `ManagesRelations`:

1. Crear `PivotMutationCoordinator` para `attachRelation()`, `detachRelation()` y `updatePivotRelation()`.
2. Mover `processSingleAttach`, `processBulkAttach`, `processSyncAttach`, `processToggleAttach` y `buildPivotMap`.
3. Mantener `assertManyToMany()`, resolucion de padre/data y `executeMutation()` como callbacks del trait.
4. Cubrir attach single, bulk attach, sync, detach y update pivot sobre una relacion `BelongsToMany` real.
5. Revisar en un corte posterior si el trait debe conservar solo configuracion/callbacks o si conviene extraer tambien query filters de relaciones.

## Undecimo corte recomendado

Los filtros/query de relaciones pueden salir del trait manteniendo wrappers protegidos:

1. Crear `RelationQueryFilter` para `eq`, `oper`, condiciones individuales y `orderby`.
2. Mantener `ManagesRelations::applyEqFilters()`, `applyOperFilters()`, `applySingleCondition()` y `applyOrdering()` como fachada compatible.
3. Hacer que `RelationReadCoordinator` use el colaborador directamente en lugar de callbacks desde el trait.
4. Cubrir arrays, nulls, `between`, `in`, `like` y ordenamiento simple sobre una relacion real.
5. No eliminar todavia `HasDynamicOrderBy` del trait para evitar romper consumidores que dependan indirectamente de ese metodo protegido.
6. Cubrir por reflexion que los entry-points publicos de `ManagesRelations` no desaparecen durante la extraccion.

## Duodecimo corte recomendado

La validacion de existencia debe separarse sin mover las reglas publicas:

1. Crear `DatabaseExistenceChecker` para normalizacion de IDs, consultas, condiciones, cache, soft delete, estados, rangos de fecha y custom query.
2. Mantener `ValidatesExistenceInDatabase` como fachada compatible para reglas, requests y consumidores directos.
3. No cambiar constructores ni mensajes de las reglas publicas en este corte.
4. Cubrir el checker con pruebas directas sobre condiciones escalares, condiciones array, soft delete, estados, rangos de fecha y custom query.
5. Dejar para un corte posterior la extraccion de mensajes de error y la reduccion de duplicacion en cada regla.

## Decimotercer corte recomendado

La duplicacion repetida dentro de reglas de existencia puede salir a un soporte comun:

1. Crear `ValidationRuleSupport` para normalizar valor de entrada, extraer IDs y construir errores compatibles.
2. Mantener constructores, interfaces y mensajes de las reglas publicas.
3. Mantener `ValidatesExistenceInDatabase::extractIds()` y `buildConditionsMessage()` como fachadas compatibles.
4. Actualizar solo reglas de existencia: `IdsExistInTable`, `IdsExistNotDelete`, `IdsExistWithAnyStatus`, `IdsExistWithDateRange` e `IdsWithCustomQuery`.
5. Cubrir los mensajes exactos y el formato de condiciones con pruebas directas del soporte.

## Decimocuarto corte recomendado

Las reglas de unicidad compuesta y unicidad en pivotes pueden compartir soporte interno:

1. Crear `UniqueValidationSupport` para mensajes por indice, deteccion de duplicados, ignore por item y consultas DB/pivot.
2. Mantener constructores e interfaces de `UniqueCompositeInArray`, `UniqueInPivot` y `UniqueInPivotArray`.
3. Conservar los mensajes actuales de validacion para no romper tests ni clientes que los comparen.
4. Centralizar soft delete de tabla principal y pivot en una sola ruta de consulta.
5. Cubrir duplicados, ignore, condiciones compuestas y pivotes con pruebas directas del soporte.

## Decimoquinto corte recomendado

El helper legacy `HelpersValidations` debe quedar como fachada, no como sitio de logica:

1. Crear `UpdateArrayUniqueValidator` para parsear atributos de arrays de update y ejecutar `Rule::unique`.
2. Mantener `HelpersValidations::validateUniqueValueInUpdateArray()` con la firma actual, incluyendo el parametro legacy `$dbconection`.
3. Preservar el comportamiento historico de buscar el ID en `users[index][id]` por defecto.
4. Devolver mensajes desde el soporte para poder probarlo sin acoplarlo al callback `$fail`.
5. Cubrir conflicto, self-ignore y ausencia de ID con pruebas usando `Validator` y SQLite reales.

## Decimosexto corte recomendado

El primer corte de permisos debe aislar payload sin tocar resolucion ni Spatie:

1. Crear `PermissionPayloadBuilder` para normalizar flags de request y construir respuestas planas o comprimidas.
2. Mantener `HasReadableUserPermissions::permissionsPayload()` como metodo publico compatible.
3. Conectar el builder al trait mediante callbacks hacia `permissionsFiltered()` y `effectivePermissionsCompressed()`.
4. Cubrir `guard`, `modules`, `entities`, contexto, filas planas y opciones de compresion.
5. Dejar `permissionsFiltered()` y `permissionCompressionUniverse()` para cortes posteriores porque tocan consultas y contratos de permisos.

## Decimoseptimo corte recomendado

Los filtros de permisos deben vivir en un colaborador comun:

1. Crear `PermissionFilter` para filtrar colecciones por `guard`, `module` y `entity`.
2. Mantener `HasReadableUserPermissions::permissionsFiltered()` como fachada compatible.
3. Mantener `HasReadableRolePermissions::permissionsFiltered()` como fachada compatible.
4. Preservar la regla de roles: permisos no restringidos saltan modulo/entidad, pero siguen respetando `guard`.
5. Cubrir filtro directo, filtro via trait de usuario y filtro via trait de rol.

## Riesgos

- `BaseService` tiene consumidores potenciales extendiendolo; mover metodos protegidos seria riesgoso, pero la mayoria de cache actual es privada.
- Cambios en validacion pueden romper payloads validos en consumidores.
- Cambios en permisos pueden romper integraciones con Spatie o modelos propios.
- Cambios en `BaseModel` pueden afectar herencia de modelos existentes.
