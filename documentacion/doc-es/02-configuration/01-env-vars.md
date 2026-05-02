# Variables de entorno

El paquete lee variables de entorno unicamente desde el archivo de configuracion. Esto es seguro para config caching.

| Variable | Default | Usado para |
| --- | --- | --- |
| `LOG_LEVEL` | `debug` | Define el nivel de log del canal de logging del paquete. |
| `LOG_QUERY` | `false` | Habilita el logging de consultas en controladores cuando es `true`. |
| `REST_VALIDATE_COLUMNS` | `true` | Habilita validacion de columnas para filtrado. |
| `REST_STRICT_COLUMNS` | `true` | Habilita comportamiento de validacion estricta de columnas. |
| `REST_VALIDATION_CACHE_ENABLED` | `true` | Activa/desactiva el cache de queries de validacion de BD (trait `ValidatesExistenceInDatabase`). |
| `REST_VALIDATION_CACHE_TTL` | `3600` | TTL del cache en segundos para queries de validacion. |
| `REST_VALIDATION_CACHE_PREFIX` | `'validation'` | Prefijo para las claves de cache de validacion. |
| `REST_VALIDATION_CONNECTION` | `'db'` | Nombre de la conexion de BD usada por las queries de validacion. |
| `REST_PERMISSIONS_ROUTES_ENABLED` | `false` | Habilita las rutas de lectura de permisos provistas por el paquete. |
| `REST_PERMISSIONS_ROUTES_PREFIX` | `permissions` | Prefijo para las rutas opcionales de permisos. |
| `REST_PERMISSIONS_ROUTES_MIDDLEWARE` | `api,auth:api` | Lista de middleware separada por comas para las rutas opcionales. |
| `REST_PERMISSIONS_ROUTES_GUARD` | `api` | Guard usado para resolver el usuario autenticado en rutas opcionales de permisos. |

**Siguiente:** [Estrategia de cache](03-cache-strategy.md)

[Volver al indice de documentacion](../index.md)
