# Variables de entorno

El paquete lee variables de entorno únicamente desde el archivo de configuración. Esto es seguro para config caching.

| Variable | Default | Usado para |
| --- | --- | --- |
| `LOG_LEVEL` | `debug` | Define el nivel de log del canal de logging del paquete. |
| `LOG_QUERY` | `false` | Habilita el logging de consultas en controladores cuando es `true`. |
| `REST_VALIDATE_COLUMNS` | `true` | Habilita validación de columnas para filtrado. |
| `REST_STRICT_COLUMNS` | `true` | Habilita comportamiento de validación estricta de columnas. |

**Siguiente:** [Publicar assets](02-publishing-assets.md)

[Volver al índice de documentación](../index.md)
