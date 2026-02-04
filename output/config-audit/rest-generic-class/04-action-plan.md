# Action Plan

## PR1 (Quick wins: docs)
- Actualizar documentación para reflejar config actual (eliminar/ajustar env no soportadas).
- Añadir nota de `LOG_QUERY` y uso recomendado (config vs env).
- Verificación:
  - Revisar README/docs en español/inglés.

## PR2 (Medium risk: config alignment)
- Declarar `rest-generic-class.logging.channel.*` y `rest-generic-class.logging.query` en config.
- Evitar `env()` en runtime dentro del ServiceProvider/Controller (usar `config()`).
- Verificación:
  - `php artisan config:clear && php artisan config:cache`
  - Smoke tests del paquete (si existen).

## PR3 (High risk: limpieza de keys no usadas)
- Confirmar si `allowed_operators`, `validate_columns`, `strict_column_validation`, `column_cache_ttl` son usados por consumers.
- Si no hay uso: deprecación + eliminación en versión mayor.

## Manual verification checklist
- ¿`Log::channel('rest-generic-class')` crea logs con el nivel esperado?
- ¿`LOG_QUERY` activa/desactiva el logging de acciones?
- ¿Las consultas con filtros respetan `max_depth`/`max_conditions`/`strict_relations`?

## Rollback strategy
- Revertir PR2 si hay apps que dependan del comportamiento actual de `env()` en runtime.
- Mantener backward compatibility en config keys durante al menos una release.
