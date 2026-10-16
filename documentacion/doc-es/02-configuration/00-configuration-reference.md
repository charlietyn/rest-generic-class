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
| `filtering.max_depth` | int | `5` | Profundidad máxima de anidación para filtros `oper`. |
| `filtering.max_conditions` | int | `100` | Número máximo de condiciones de filtro por solicitud. |
| `filtering.strict_relations` | bool | `true` | Requiere `const RELATIONS` en los modelos (recomendado). |
| `filtering.allowed_operators` | array | (ver configuración) | Operadores permitidos para el filtrado `oper`. |
| `filtering.validate_columns` | bool | `true` | Valida nombres de columnas antes de aplicar filtros. |
| `filtering.strict_column_validation` | bool | `true` | Aplica validación estricta de columnas. |
| `filtering.column_cache_ttl` | int | `3600` | TTL de caché (segundos) para listas de columnas. |

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
