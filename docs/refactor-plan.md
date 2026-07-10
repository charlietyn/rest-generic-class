# Plan senior de refactor

## Objetivo

Reducir el acoplamiento de `BaseService`, `BaseModel`, validaciones y permisos sin romper a las bibliotecas que ya consumen este paquete.

La estrategia es conservadora: primero contratos y tests, despues extraccion interna de responsabilidades. `BaseService` debe seguir existiendo como fachada estable mientras la logica se mueve a componentes mas pequenos.

## Contexto del grafo

Graphify marco estos nodos como los puntos de mayor riesgo:

- `BaseService`: eje principal entre consultas, relaciones, cache, modelos, controladores y documentacion.
- `BaseModel`: base de convenciones para modelos consumidores.
- Validaciones de base de datos: contrato sensible para requests, relaciones e imports.
- Permisos: limite arquitectonico entre Spatie, contratos propios y middleware.
- Documentacion de API publica y uso avanzado: funciona como contrato para consumidores.

Las conexiones hacia `composer.json` como `name` parecen ruido de extraccion, no dependencia arquitectonica fuerte.

## Principios

- No romper API publica en una version menor.
- Mantener `BaseService` y `BaseModel` como puntos de compatibilidad durante la transicion.
- Extraer logica interna detras de metodos existentes.
- Marcar deprecaciones solo cuando exista reemplazo claro.
- Proteger cada cambio con tests de contrato que simulen bibliotecas consumidoras.

## Fase 1: inventario y contratos

1. Ejecutar la suite actual.
2. Listar metodos publicos y protegidos de `BaseService`, `BaseModel`, `RestController`, traits, reglas de validacion y permisos.
3. Clasificar cada metodo como:
   - API publica estable.
   - Punto de extension permitido.
   - Implementacion interna.
   - Candidato a deprecacion.
4. Crear tests de contrato para:
   - Servicio custom extendiendo `BaseService`.
   - Modelo custom extendiendo `BaseModel`.
   - Filtros, ordenamiento y paginacion.
   - Relaciones one-to-many y many-to-many.
   - Soft delete.
   - Cache invalidation.
   - Validacion de IDs y reglas compuestas.
   - Permisos con Spatie y con contratos propios.

## Fase 2: adelgazar `BaseService`

Extraer responsabilidades internas sin cambiar firmas existentes:

- `QueryBuilderPipeline`: filtros, busqueda, ordenamiento y paginacion.
- `RelationService`: attach, detach, sync, update pivot y listado de relaciones.
- `CacheCoordinator`: estrategia, keys, tags e invalidacion.
- `ModelResolver`: resolucion de modelo, soft delete awareness y convenciones.
- `PermissionGate`: autorizacion, Spatie y proveedores propios.

`BaseService` debe delegar en estos componentes y conservar sus metodos actuales.

## Fase 3: desacoplar `BaseModel`

1. Identificar que necesita realmente `BaseService` de un modelo.
2. Crear contratos pequenos:
   - `HasRestRelations`
   - `HasRestCache`
   - `HasRestPermissions`
   - `HasRestSoftDeletes`
3. Permitir modelos externos por contrato, no solo por herencia de `BaseModel`.
4. Mantener `BaseModel` como implementacion conveniente de esos contratos.

## Fase 4: estabilizar validacion

1. Agrupar reglas por intencion:
   - Existencia en base de datos.
   - Existencia ignorando soft delete.
   - Existencia incluyendo eliminados.
   - Arrays de IDs.
   - Pivots.
   - Reglas compuestas.
2. Cubrir reglas con tests especificos.
3. Documentar comportamiento exacto con soft delete.
4. Separar parseo de errores de base de datos de reglas de negocio.

## Fase 5: aislar permisos

1. Definir un contrato interno para proveedores de permisos.
2. Crear adaptador para Spatie.
3. Crear fallback para contratos propios.
4. Mantener middleware y traits actuales como capa compatible.
5. Testear:
   - Usuario sin roles.
   - Rol inexistente.
   - Permiso faltante.
   - Permisos comprimidos.
   - Contratos mal implementados.

## Fase 6: documentacion y versionado

1. Marcar API estable, API avanzada, API interna y features experimentales.
2. Sincronizar documentacion en ingles y espanol.
3. Publicar guia de migracion.
4. Version menor:
   - Nuevas clases internas.
   - API antigua compatible.
   - Deprecaciones.
   - Tests de contrato.
5. Version mayor:
   - Eliminacion de deprecated.
   - Contratos obligatorios si aplica.
   - `BaseService` simplificado.

## Metricas de exito

- Menos logica directa en `BaseService`.
- Tests de contrato cubriendo consumidores externos.
- Metodos publicos clasificados.
- Cambios breaking documentados.
- Validacion, permisos, cache y relaciones con tests propios.
- Menor acoplamiento observado en Graphify tras el refactor.

## Orden de ejecucion recomendado

1. Guardar este plan.
2. Ejecutar tests actuales.
3. Crear inventario de API publica.
4. Anadir tests de contrato.
5. Extraer relaciones.
6. Extraer query/filter/order/pagination.
7. Extraer cache.
8. Extraer permisos.
9. Desacoplar `BaseModel`.
10. Actualizar documentacion.

## Avance ejecutado

- Plan guardado en `docs/refactor-plan.md`.
- Mapa inicial de contrato publico creado en `docs/refactor-contract-map.md`.
- Linea base validada con PHPUnit antes de cambios: 43 tests, 104 assertions.
- Primer corte de refactor aplicado: cache extraida desde `BaseService` hacia `CacheCoordinator`.
- Contratos pequenos de modelo agregados: cache, relaciones, soft delete y permisos de campo.
- `BaseModel` implementa los contratos nuevos sin cambiar comportamiento existente.
- `BaseService` usa el contrato de relaciones cuando esta disponible y conserva fallback por constante.
- `CacheCoordinator` usa el contrato de cache cuando esta disponible y conserva fallback por constante.
- Validacion posterior: 48 tests, 123 assertions.
- Segundo corte de refactor aplicado: resolucion y normalizacion de relaciones extraida hacia `RelationResolver`.
- `BaseService` conserva wrappers privados para relaciones, pero delega la ruta activa al resolver.
- `RelationResolver` cubre relaciones permitidas, shortcut `all`, parseo de campos, filtros por relacion, modelo relacionado y campos requeridos.
- Validacion posterior al corte de relaciones: 52 tests, 131 assertions.
- Tercer corte de refactor aplicado: orquestacion de query extraida hacia `QueryBuilderPipeline`.
- `BaseService::process_query()` y `BaseService::process_all()` conservan firma y delegan en el pipeline.
- El pipeline coordina attr, oper, relaciones, select, orderby y paginacion mediante callbacks hacia la logica existente.
- Validacion posterior al corte de query pipeline: 54 tests, 139 assertions.
- Cuarto corte de refactor aplicado: motor de filtros `oper` extraido hacia `OperFilterPipeline`.
- `BaseService::applyOperTree()` y `BaseService::applyNestedWhereHas()` conservan firma privada y delegan en el nuevo colaborador.
- El nuevo colaborador mantiene limites de profundidad, conteo de condiciones, filtros base y `whereHas` anidados.
- Validacion posterior al corte de filtros: 58 tests, 150 assertions.
- Quinto corte de refactor aplicado: paginacion extraida hacia `PaginationCoordinator`.
- `BaseService::pagination()` y `BaseService::process_pagination()` conservan firma y delegan en el nuevo coordinador.
- El coordinador cubre paginacion normal, `pageSize`/`pagesize` y cursor pagination con `infinity`.
- Validacion posterior al corte de paginacion: 61 tests, 160 assertions.
- Sexto corte de refactor aplicado: exportacion extraida hacia `ExportCoordinator`.
- `BaseService::exportExcel()` y `BaseService::exportPdf()` conservan firma y delegan en el coordinador.
- El coordinador cubre preparacion de payload, extraccion de datos y resolucion de columnas.
- Los tests no invocan paquetes opcionales de Excel/PDF; validan la logica propia del paquete.
- Validacion posterior al corte de exportacion: 64 tests, 170 assertions.
- Septimo corte de refactor aplicado: jerarquias extraidas hacia `HierarchyCoordinator`.
- `BaseService::listHierarchy()` y `BaseService::showHierarchy()` conservan firma y delegan en el coordinador.
- El coordinador cubre normalizacion de parametros, modos de filtro, carga de ancestros/descendientes, armado de arbol y paginacion de raices.
- Se agregaron tests directos para arboles, `children_key`, fallback sin jerarquia, paginacion e input invalido.
- Validacion posterior al corte de jerarquia: 70 tests, 185 assertions.
- Octavo corte de refactor aplicado: lectura/exportacion de `ManagesRelations` extraida hacia `RelationReadCoordinator`.
- `ManagesRelations::listRelation()`, `showRelation()`, `exportRelationExcel()`, `exportRelationPdf()`, `processRelationPagination()` y `parseRelationParams()` conservan firma y delegan.
- El coordinador cubre parseo de parametros, listado, show, payload de exportacion, columnas y paginacion de relacion.
- Las mutaciones `create/update/delete/attach/detach/updatePivot` quedan fuera de este corte para no mezclar semantica de pivots con lectura.
- Validacion posterior al corte de lectura de relaciones: 75 tests, 201 assertions.
- Noveno corte de refactor aplicado: mutaciones compartidas de `ManagesRelations` extraidas hacia `RelationMutationCoordinator`.
- `ManagesRelations::createRelation()`, `updateRelation()` y `deleteRelation()` conservan firma y delegan en el coordinador.
- El coordinador cubre create single/bulk, update single/bulk, delete single/bulk, errores not-found y delega la frontera transaccional a `executeMutation()`.
- Las mutaciones exclusivas M2M `attachRelation()`, `detachRelation()` y `updatePivotRelation()` quedan pendientes para `PivotMutationCoordinator`.
- Validacion posterior al corte de mutaciones de relaciones: 79 tests, 219 assertions.
- Decimo corte de refactor aplicado: mutaciones pivot M2M extraidas hacia `PivotMutationCoordinator`.
- `ManagesRelations::attachRelation()`, `detachRelation()` y `updatePivotRelation()` conservan firma y delegan en el coordinador.
- El coordinador cubre attach single/bulk, sync, toggle, detach, update pivot y normalizacion de mapas pivot con whitelist de columnas.
- Validacion focalizada del corte pivot: 5 tests, 21 assertions.
- Validacion posterior al corte pivot: 84 tests, 240 assertions.
- Undecimo corte de refactor aplicado: filtros/query de relaciones extraidos hacia `RelationQueryFilter`.
- `ManagesRelations::applyEqFilters()`, `applyOperFilters()`, `applySingleCondition()` y `applyOrdering()` conservan firma protegida y delegan en el nuevo colaborador.
- `RelationReadCoordinator` deja de recibir callbacks de filtrado desde el trait y usa `RelationQueryFilter` directamente para listado y exportacion.
- El nuevo colaborador cubre filtros `eq`, condiciones `oper` y ordenamiento dinamico sobre relaciones.
- Se agrego un test de contrato para asegurar que los entry-points publicos de `ManagesRelations` siguen disponibles.
- Validacion focalizada del corte de filtros/API de relaciones: 9 tests, 43 assertions.
- Validacion posterior al corte de filtros/API de relaciones: 88 tests, 267 assertions.
- Duodecimo corte de refactor aplicado: soporte comun de existencia en base de datos extraido hacia `DatabaseExistenceChecker`.
- `ValidatesExistenceInDatabase` conserva sus metodos publicos y protegidos, pero delega normalizacion de IDs, consultas, condiciones, soft delete, rangos de fecha, estados, custom query y limpieza de cache.
- Las reglas publicas (`IdsExistInTable`, `IdsExistNotDelete`, `IdsExistWithAnyStatus`, `IdsExistWithDateRange`, `IdsWithCustomQuery`) siguen usando el trait sin cambios de constructor.
- Validacion focalizada del corte de existencia en base de datos: 8 tests, 19 assertions.
- Validacion posterior al corte de existencia en base de datos: 93 tests, 278 assertions.
- Decimotercer corte de refactor aplicado: soporte comun de reglas de validacion extraido hacia `ValidationRuleSupport`.
- Las reglas de existencia conservan constructores y mensajes, pero delegan normalizacion de entrada, extraccion de IDs y adicion de errores.
- `ValidatesExistenceInDatabase::extractIds()` y `buildConditionsMessage()` conservan firma y delegan al nuevo soporte.
- Validacion focalizada del soporte de reglas: 11 tests, 26 assertions.
- Validacion posterior al corte de soporte de reglas: 96 tests, 285 assertions.
- Decimocuarto corte de refactor aplicado: soporte comun de unicidad extraido hacia `UniqueValidationSupport`.
- `UniqueCompositeInArray`, `UniqueInPivot` y `UniqueInPivotArray` conservan constructores, interfaces publicas y mensajes de validacion, pero delegan mensajes, duplicados, ignore por item, soft delete y consultas DB/pivot.
- Validacion focalizada del soporte de unicidad: 4 tests, 18 assertions.
- Validacion posterior al corte de soporte de unicidad: 100 tests, 303 assertions.
- Decimoquinto corte de refactor aplicado: helper legacy de unicidad en arrays de update extraido hacia `UpdateArrayUniqueValidator`.
- `HelpersValidations::validateUniqueValueInUpdateArray()` conserva firma publica y queda como fachada compatible que invoca el nuevo soporte.
- El soporte cubre parseo de atributo, resolucion del ID ignorado y ejecucion de `Rule::unique` con el mensaje de Laravel.
- Validacion focalizada del helper legacy de unicidad: 4 tests, 6 assertions.
- Validacion posterior al corte del helper legacy de unicidad: 104 tests, 309 assertions.
- Decimosexto corte de refactor aplicado: payload autenticado de permisos extraido hacia `PermissionPayloadBuilder`.
- `HasReadableUserPermissions::permissionsPayload()` conserva firma publica y queda como fachada compatible mediante callbacks a `permissionsFiltered()` y `effectivePermissionsCompressed()`.
- El builder cubre normalizacion de `guard`, `modules`, `entities`, contexto, filas planas y opciones de compresion.
- Validacion focalizada del payload de permisos: 5 tests, 20 assertions.
- Validacion posterior al corte de payload de permisos: 107 tests, 320 assertions.
- Decimoseptimo corte de refactor aplicado: filtros compartidos de permisos extraidos hacia `PermissionFilter`.
- `HasReadableUserPermissions::permissionsFiltered()` y `HasReadableRolePermissions::permissionsFiltered()` conservan firma publica y delegan en el nuevo filtro.
- El filtro conserva la diferencia semantica de roles: permisos no restringidos pasan por modulo/entidad, pero no saltan el filtro de `guard`.
- Plan restante de permisos guardado en `docs/refactor-permissions-remaining-plan.md`.
- Validacion focalizada del filtro de permisos: 23 tests, 55 assertions.
- Validacion posterior al corte de filtro de permisos: 111 tests, 324 assertions.
