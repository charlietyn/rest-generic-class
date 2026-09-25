# v2.5.0 — Agregaciones por parámetros

Fecha: 2026-09-25. Versión de Composer identificada por la etiqueta Git `v2.5.0`.

- Los listados admiten `aggregate` para métricas globales y `with_aggregates` para métricas por relación.
- Se admiten `count`, `sum`, `avg`, `min` y `max` mediante métodos nativos de Laravel, con autorización explícita mediante `HasRestAggregates`.
- Se conservan los filtros y las restricciones de las relaciones; las métricas por registro pueden ordenarse y paginarse.
- Las llamadas directas a `process_query()` y `process_all()` conservan los filtros `eq`/`attr` al solicitar métricas por relación.
- Las métricas exigen relaciones declaradas mediante `HasRestRelations`/`RELATIONS`, incluso si los filtros históricos permiten autodetección.
- La validación rechaza funciones, columnas, alias y combinaciones no soportadas con HTTP 400.
- Los endpoints de relaciones incorporan las mismas métricas y conservan su sintaxis histórica de filtros.
- La caché se omite cuando las métricas dependen de relaciones cuya invalidación no está garantizada.
- Las solicitudes existentes sin los nuevos parámetros mantienen su contrato. No hay migraciones de base de datos del consumidor ni dependencias nuevas.
- Se eliminan los documentos de planificación y sus enlaces obsoletos.

Consultar [documentación de agregaciones](../documentation/aggregations.md) para ejemplos, tipos de resultado, reglas de nulos, coste por consulta y funcionalidades aplazadas.
