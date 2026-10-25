# Referencia de configuración

El paquete publica un único archivo de configuración: `config/rest-generic-class.php`.

## Logging

| Clave | Tipo | Default | Descripción |
| --- | --- | --- | --- |
| `logging.rest-generic-class.driver` | string | `single` | Driver de logging usado cuando el paquete crea un canal. |
| `logging.rest-generic-class.path` | string | `storage_path('logs/rest-generic-class.log')` | Ruta del archivo de log. |
| `logging.rest-generic-class.level` | string | `debug` | Nivel de log. |
| `logging.channel.driver` | string | `single` | Definición de canal secundario usado por el service provider. |
| `logging.channel.path` | string | `storage_path('logs/rest-generic-class.log')` | Ruta del canal secundario. |
| `logging.channel.level` | string | `debug` | Nivel del canal secundario. |
| `logging.query` | bool | `false` | Cuando es true, los controladores registran acciones de consulta en `storage/logs/query.log`. |

## Filtrado

| Clave | Tipo | Default | Descripción |
| --- | --- | --- | --- |
| `filtering.max_depth` | int | `5` | Profundidad máxima de `oper`: la raíz cuenta 1 y cada salto de relación y cada grupo `and`/`or` anidado suma 1 (desde 3.1 también los grupos). Superarla: 400 «Maximum nesting depth (5) exceeded.» |
| `filtering.max_conditions` | int | `100` | Número máximo de condiciones de filtro por solicitud. |
| `filtering.strict_relations` | bool | `true` | Requiere `const RELATIONS` en los modelos (recomendado). |
| `filtering.allowed_operators` | array | (ver configuración) | Documental: el filtro valida contra su propia lista (ver [Uso básico](../03-usage/00-basic-usage.md#reglas-de-la-consulta-v310)). Desde 3.1 sin `exists`/`not exists`. |
| `filtering.validate_columns` | bool | `true` | Reservada: hoy no tiene efecto. |
| `filtering.strict_column_validation` | bool | `true` | v3.1: una columna que la tabla no tiene (o de otra tabla, o una expresión) es un 400 «Column '<col>' does not exist on <tabla>.» en `oper`, `attr`/`eq`, `select` y `orderby`, también en los endpoints de relación. `false`: llega a la base de datos como antes. |
| `filtering.allow_hidden_columns` | bool | `false` | v3.1: con `false`, una columna de `$hidden` (timestamps incluidos) es un 400 «Column '<col>' of <Modelo> cannot be used to filter\|sort.» y se omite en silencio de `select`. `true`: comportamiento anterior. |
| `filtering.reject_invalid_json` | bool | `true` | v3.1: `oper`, `attr`, `eq`, `orderby` o `pagination` con JSON inválido es un 400 «The <param> parameter is not valid JSON.». `false`: se ignora como antes. |
| `filtering.translate_regexp` | bool | `true` | v3.1: `regexp`/`not regexp` → `~`/`!~` en PostgreSQL, `REGEXP` en MySQL/MariaDB, 400 en SQLite y SQL Server. `false`: el operador se envía tal cual. |
| `filtering.column_cache_ttl` | int | `3600` | Reservada: el listado de columnas se lee una vez por conexión y tabla durante el proceso. |

## Validación

| Clave | Tipo | Default | Descripción |
| --- | --- | --- | --- |
| `validation.cache_enabled` | bool | `true` | Activa/desactiva el caché de las queries de validación en el trait `ValidatesExistenceInDatabase`. |
| `validation.cache_ttl` | int | `3600` | TTL del caché en segundos para queries de validación. |
| `validation.cache_prefix` | string | `'validation'` | Prefijo usado para las claves de caché de validación. |
| `validation.connection` | string | `'db'` | Nombre de la conexión de BD usada por las queries de validación. |

## Autorización del núcleo (v2.9.0)

Claves bajo `authorization` (núcleo, independiente de cualquier paquete de permisos). Un valor `null` recurre a
la clave antigua `permissions.authorization.*` del mismo nombre y después al valor por defecto. Ver los
[contratos de autorización](../04-reference/06-authorization-contracts.md).

| Clave | Por defecto | Significado |
| --- | --- | --- |
| `eager_relations` | `null` → `off` | `require_read` hace que cada relación a-muchos pedida con `?relations=` necesite alguna de las abilities de relación bajo el recurso autorizado, consultadas al Gate de Laravel. Sin una integración de permisos que aporte el ámbito de la petición, falla de forma cerrada (`MissingAuthorizationContextException`). |
| `eager_relations_to_one` | `null` → `allow` | `allow` deja exentas las relaciones a-uno; `require_read` también las comprueba. |
| `relation_abilities` | `['read', 'manage']` | Sufijos de las abilities que conceden una relación (`<recurso>.<relación>.<sufijo>`, cualquiera de ellos). |

## Permisos (ronu/rest-permissions)

Desde 3.0 los permisos por rutas (autorización por canales, catálogo de permisos, rutas de permisos, contratos de usuario y rol, y sus variables `REST_AUTHZ_*` / `REST_PERMISSIONS_*`) viven en [`ronu/rest-permissions`](https://github.com/charlietyn/rest-permissions/blob/main/docs/es/configuration.md). El núcleo solo conserva `authorization.*`, descrito arriba.

**Siguiente:** [Variables de entorno](01-env-vars.md)

[Volver al índice de documentación](../index.md)
