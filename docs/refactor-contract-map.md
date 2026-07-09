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

## Riesgos

- `BaseService` tiene consumidores potenciales extendiendolo; mover metodos protegidos seria riesgoso, pero la mayoria de cache actual es privada.
- Cambios en validacion pueden romper payloads validos en consumidores.
- Cambios en permisos pueden romper integraciones con Spatie o modelos propios.
- Cambios en `BaseModel` pueden afectar herencia de modelos existentes.
