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
