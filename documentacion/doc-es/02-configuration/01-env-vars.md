# Variables de entorno

El paquete lee variables de entorno únicamente desde el archivo de configuración. Esto es seguro para config caching.

| Variable | Default | Usado para |
| --- | --- | --- |
| `LOG_LEVEL` | `debug` | Define el nivel de log del canal de logging del paquete. |
| `LOG_QUERY` | `false` | Habilita el logging de consultas en controladores cuando es `true`. |
| `REST_VALIDATE_COLUMNS` | `true` | Habilita validación de columnas para filtrado. |
| `REST_STRICT_COLUMNS` | `true` | Habilita comportamiento de validación estricta de columnas. |
| `REST_VALIDATION_CACHE_ENABLED` | `true` | Activa/desactiva el caché de queries de validación de BD (trait `ValidatesExistenceInDatabase`). |
| `REST_VALIDATION_CACHE_TTL` | `3600` | TTL del caché en segundos para queries de validación. |
| `REST_VALIDATION_CACHE_PREFIX` | `'validation'` | Prefijo para las claves de caché de validación. |
| `REST_VALIDATION_CONNECTION` | `'db'` | Nombre de la conexión de BD usada por las queries de validación. |

**Siguiente:** [Estrategia de caché](03-cache-strategy.md)

[Volver al índice de documentación](../index.md)
