# Config keys reference

| Key | Default | Description | Evidence |
| --- | --- | --- | --- |
| `logging.rest-generic-class.driver` | `single` | Driver para canal de logs. | `config/rest-generic-class.php` |
| `logging.rest-generic-class.path` | `storage_path('logs/rest-generic-class.log')` | Ruta del log. | `config/rest-generic-class.php` |
| `logging.rest-generic-class.level` | `env('LOG_LEVEL','debug')` | Nivel de log. | `config/rest-generic-class.php` |
| `filtering.max_depth` | `5` | Profundidad máxima de filtros anidados. | `config/rest-generic-class.php` |
| `filtering.max_conditions` | `100` | Máximo de condiciones en `oper`. | `config/rest-generic-class.php` |
| `filtering.strict_relations` | `true` | Enforce de `RELATIONS` en modelos. | `config/rest-generic-class.php` |
| `filtering.allowed_operators` | lista | Operadores permitidos en filtros. | `config/rest-generic-class.php` |
| `filtering.validate_columns` | `env('REST_VALIDATE_COLUMNS', true)` | Declarado en config. | `config/rest-generic-class.php` |
| `filtering.strict_column_validation` | `env('REST_STRICT_COLUMNS', true)` | Declarado en config. | `config/rest-generic-class.php` |
| `filtering.column_cache_ttl` | `3600` | TTL para cache de columnas (declarado). | `config/rest-generic-class.php` |

## Evidence
- File: config/rest-generic-class.php
  - Symbol: returned array
  - Notes: fuente de verdad para claves.
