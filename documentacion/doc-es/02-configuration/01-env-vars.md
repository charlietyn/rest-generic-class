# Variables de entorno

El paquete lee variables de entorno unicamente desde el archivo de configuracion. Esto es seguro para config caching.

| Variable | Default | Usado para |
| --- | --- | --- |
| `LOG_LEVEL` | `debug` | Define el nivel de log del canal de logging del paquete. |
| `LOG_QUERY` | `false` | Habilita el logging de consultas en controladores cuando es `true`. |
| `REST_VALIDATE_COLUMNS` | `true` | Reservada: hoy no tiene efecto (la validación de columnas la gobierna `REST_STRICT_COLUMNS`). |
| `REST_STRICT_COLUMNS` | `true` | v3.1: una columna que la tabla no tiene es un 400 en `oper`, `attr`/`eq`, `select` y `orderby` (`filtering.strict_column_validation`). |
| `REST_ALLOW_HIDDEN_COLUMNS` | `false` | v3.1: `true` vuelve a permitir filtrar y ordenar por columnas ocultas (`filtering.allow_hidden_columns`). |
| `REST_REJECT_INVALID_JSON` | `true` | v3.1: un parámetro estructurado con JSON inválido es un 400 (`filtering.reject_invalid_json`). |
| `REST_TRANSLATE_REGEXP` | `true` | v3.1: `regexp` se traduce por driver (`filtering.translate_regexp`). |
| `REST_VALIDATION_CACHE_ENABLED` | `true` | Activa/desactiva el cache de queries de validacion de BD (trait `ValidatesExistenceInDatabase`). |
| `REST_VALIDATION_CACHE_TTL` | `3600` | TTL del cache en segundos para queries de validacion. |
| `REST_VALIDATION_CACHE_PREFIX` | `'validation'` | Prefijo para las claves de cache de validacion. |
| `REST_VALIDATION_CONNECTION` | `'db'` | Nombre de la conexion de BD usada por las queries de validacion. |

## Autorización del núcleo

| Variable | Default | Usado para |
| --- | --- | --- |
| `REST_AUTHZ_EAGER_RELATIONS` | `off` | v2.8.0: `off` o `require_read` (autoriza las cargas anidadas de `?relations=`). Desde v2.9.0 alimenta también `authorization.eager_relations`. |
| `REST_AUTHZ_EAGER_RELATIONS_TO_ONE` | `allow` | v2.8.0: `allow` (relaciones a uno exentas) o `require_read`. |

Las variables `REST_AUTHZ_*` de la autorización por canales y las `REST_PERMISSIONS_*` pertenecen a [`ronu/rest-permissions`](https://github.com/charlietyn/rest-permissions/blob/main/docs/es/configuration.md#variables-de-entorno) desde 3.0.

**Siguiente:** [Estrategia de cache](03-cache-strategy.md)

[Volver al indice de documentacion](../index.md)
