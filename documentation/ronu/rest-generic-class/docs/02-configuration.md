# Configuration

## Config file
Publica la configuración si necesitas ajustar logging o límites de filtrado.

`config/rest-generic-class.php` contiene:
- Configuración de logging para el canal `rest-generic-class`.
- Límites de filtrado y seguridad de relaciones.

### Tabla de claves de configuración
| Key | Tipo | Default | Descripción |
| --- | --- | --- | --- |
| `logging.rest-generic-class.driver` | string | `single` | Driver del canal de logging. |
| `logging.rest-generic-class.path` | string | `storage_path('logs/rest-generic-class.log')` | Ruta del log. |
| `logging.rest-generic-class.level` | string | `env('LOG_LEVEL','debug')` | Nivel de log. |
| `filtering.max_depth` | int | `5` | Profundidad máxima de filtros anidados. |
| `filtering.max_conditions` | int | `100` | Límite de condiciones en `oper`. |
| `filtering.strict_relations` | bool | `true` | Requiere `RELATIONS` por modelo para evitar exposición. |
| `filtering.allowed_operators` | array | ver config | Operadores permitidos por el motor de filtros. |
| `filtering.validate_columns` | bool | `env('REST_VALIDATE_COLUMNS', true)` | Declarado en config, sin uso directo visible en el código actual. |
| `filtering.strict_column_validation` | bool | `env('REST_STRICT_COLUMNS', true)` | Declarado en config, sin uso directo visible en el código actual. |
| `filtering.column_cache_ttl` | int | `3600` | TTL en segundos (declarado, sin uso directo visible). |

### Variables de entorno
| Variable | Default | Uso |
| --- | --- | --- |
| `LOG_LEVEL` | `debug` | Nivel del canal `rest-generic-class`. |
| `REST_VALIDATE_COLUMNS` | `true` | Declarada en config; no se observa uso directo. |
| `REST_STRICT_COLUMNS` | `true` | Declarada en config; no se observa uso directo. |
| `LOG_QUERY` | `false` | Activa logging de llamadas a acciones del controller. |

## Discrepancias detectadas vs docs existentes
- Los docs existentes mencionan `REST_MAX_DEPTH`, `REST_MAX_CONDITIONS` y `REST_STRICT_RELATIONS`, pero el config actual no expone esas variables; usa valores fijos en `config/rest-generic-class.php` para `max_depth`, `max_conditions` y `strict_relations` (sólo `REST_VALIDATE_COLUMNS` y `REST_STRICT_COLUMNS` aparecen en el archivo de configuración).
- El Service Provider lee `rest-generic-class.logging.channel.*`, pero el archivo de configuración define `logging.rest-generic-class.*` (nombres de keys no coinciden).
## Evidence
- File: config/rest-generic-class.php
  - Symbol: array config
  - Notes: claves de configuración y variables env.
- File: src/Core/Providers/RestGenericClassServiceProvider.php
  - Symbol: RestGenericClassServiceProvider::boot()
  - Notes: canal `rest-generic-class` y publish tag.
- File: docs/Documentation.md
  - Symbol: sección de configuración/envs
  - Notes: menciona `REST_MAX_DEPTH`/`REST_MAX_CONDITIONS`/`REST_STRICT_RELATIONS`.
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController::callAction()
  - Notes: uso de `LOG_QUERY` para log de acciones.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::{getRelationsForModel,applyOperTree}
  - Notes: uso de `filtering.strict_relations`, `max_depth`, `max_conditions`.
