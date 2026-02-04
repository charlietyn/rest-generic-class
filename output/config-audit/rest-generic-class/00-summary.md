# Config/ENV Audit Summary — ronu/rest-generic-class

## Executive summary
Proyecto identificado como **package** (Laravel package). Se detectaron inconsistencias entre configuración y uso en runtime, además de variables documentadas pero no consumidas. El mayor riesgo actual es el uso de `env()` en runtime (no compatible con `config:cache`) y la discrepancia entre las keys declaradas en `config/rest-generic-class.php` y las que consume el `ServiceProvider`.

## Metrics
- ENV declared (docs/env files): **7**
- ENV used (runtime/config): **4**
- Unused ENV (declared, not used): **3**
- Missing docs for ENV (used, not documented): **0**
- Typo/mismatch candidates: **1** (logging channel key mismatch)
- Dead config keys (declared, not read in package): **7**

## Top risks & recommendations
1. **Runtime `env()` usage** (`LOG_QUERY`, `LOG_LEVEL` fallback) puede romper `config:cache` y generar defaults inesperados.
2. **Mismatch de config keys**: `rest-generic-class.logging.channel.*` se lee en runtime pero no existe en `config/rest-generic-class.php`.
3. **Documentación vs. implementación**: docs muestran env vars para `REST_MAX_DEPTH/CONDITIONS/STRICT_RELATIONS` pero no existen en runtime.

## Recommended actions (safe)
- Añadir keys faltantes en `config/rest-generic-class.php` para `logging.channel.*` y `logging.query`.
- Reemplazar `env()` en runtime por `config()`.
- Ajustar docs para reflejar la implementación real (o introducir esas ENV en config si se decide soportarlas).
