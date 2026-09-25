# Validación de agregaciones

Fecha: 2026-09-25. Ejecución local sobre el código de esta entrega.

## Base antes del cambio

Laravel 13.12.0, PHP 8.5.0: 167 pruebas, 431 aserciones, sin fallos. Dos avisos de deprecación preexistentes.

## Matriz de regresión

La suite completa incluye las pruebas de agregaciones y las pruebas históricas del paquete.

| Laravel | PHP | PHPUnit | Resultado final |
| --- | --- | --- | --- |
| 12.61.1 | 8.2.30 | 11.5.56 | 237 pruebas, 592 aserciones |
| 12.61.1 | 8.3.28 | 11.5.56 | 237 pruebas, 592 aserciones |
| 13.12.0 | 8.3.28 | 12.5.12 | 237 pruebas, 592 aserciones |
| 13.12.0 | 8.4.15 | 12.5.12 | 237 pruebas, 592 aserciones |
| 13.12.0 | 8.5.0 | 12.5.12 | 237 pruebas, 592 aserciones |

Las ejecuciones con Laravel 13 y PHP 8.4/8.5 conservan los dos avisos de deprecación de la suite base. La suite específica de agregaciones no los genera.

## Revisión de continuidad

Se reprodujeron cuatro fallos antes de corregir dos lagunas del contrato:

- `process_query()` y `process_all()` ignoraban `eq` cuando se usaban directamente con `with_aggregates`. Ahora combinan `eq` y `attr`, incluidos sus formatos JSON, con prioridad de `attr` para campos repetidos.
- La autorización de métricas reutilizaba la autodetección de relaciones cuando `strict_relations=false`; sin declaración y con modo estricto devolvía HTTP 500. Ahora exige `HasRestRelations`/`RELATIONS` y rechaza con HTTP 400 antes de consultar datos, independientemente del modo histórico.

Se añadieron cinco casos de regresión, incluida la autorización por interfaz sin constante. La suite específica pasa con 70 pruebas y 161 aserciones en SQLite; la matriz completa anterior se volvió a ejecutar tras estas correcciones. `composer validate --strict` también pasa.

## Bases de datos reales

La validación anterior a la revisión de continuidad ejecutó `AggregationTest`: 65 pruebas y 148 aserciones por combinación. Los cinco casos nuevos y las correcciones de continuidad no se han vuelto a ejecutar en MySQL/PostgreSQL; las cifras siguientes corresponden a aquella ejecución, no a la suite actual de 70 pruebas.

| Laravel | PHP | Motor |
| --- | --- | --- |
| 12.61.1 | 8.2.30 | PostgreSQL 13 |
| 12.61.1 | 8.2.30 | MySQL 8.4.7 |
| 13.12.0 | 8.5.0 | PostgreSQL 13 |
| 13.12.0 | 8.5.0 | MySQL 8.4.7 |

SQLite se verifica en todas las ejecuciones de la suite completa. PostgreSQL se ejecutó en una instancia temporal y MySQL en una base de pruebas dedicada. Para PHP 8.2 se habilitó `pdo_pgsql` solo mediante una opción de la ejecución, sin modificar el `php.ini` del usuario.

## Comprobaciones adicionales

- `composer validate --strict`.
- Parseo del YAML del workflow de pruebas.
- Revisión de diferencias y comprobación de whitespace de Git con la configuración de finales de línea del repositorio.
- Las dependencias de Laravel 12 se instalaron en una copia temporal; no se modificaron `composer.json`, `composer.lock` ni `vendor` del proyecto.
- CI incorpora jobs para MySQL 8.4 y PostgreSQL 16 en Laravel 12/13. PostgreSQL 16 queda cubierto por la configuración de CI, no por la ejecución local con PostgreSQL 13.

## Límites documentados

- Las métricas globales ejecutan una consulta por métrica y no garantizan una instantánea entre consultas concurrentes.
- Las métricas dependientes de relaciones omiten la caché para evitar inconsistencias con rutas de escritura sin invalidación universal.
- La colocación de `NULL` al ordenar sigue las reglas del motor antes de normalizar el resultado.
- No se incluyen las ampliaciones de agrupación, `having`, `distinct`, rutas profundas arbitrarias ni `morphTo`.
- La semántica de scopes intermedios en relaciones Through es la nativa de Laravel; la aplicación declara las restricciones adicionales en la relación.

Esta validación local corresponde a la entrega v2.5.0. Los resultados de CI remoto
se consultan en GitHub Actions para el commit o la etiqueta de la versión.
