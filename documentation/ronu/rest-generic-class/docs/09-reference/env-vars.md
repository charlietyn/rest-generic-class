# Environment variables

| Variable | Default | Used by | Notes | Evidence |
| --- | --- | --- | --- | --- |
| `LOG_LEVEL` | `debug` | logging channel | Nivel del canal `rest-generic-class`. | `config/rest-generic-class.php` |
| `REST_VALIDATE_COLUMNS` | `true` | filtering config | Declarada en config; no se observa uso directo. | `config/rest-generic-class.php` |
| `REST_STRICT_COLUMNS` | `true` | filtering config | Declarada en config; no se observa uso directo. | `config/rest-generic-class.php` |
| `LOG_QUERY` | `false` | RestController | Log de llamadas a acciones. | `src/Core/Controllers/RestController.php` |

## Evidence
- File: config/rest-generic-class.php
  - Symbol: env(...) calls
  - Notes: defaults de variables.
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController::callAction()
  - Notes: uso de `LOG_QUERY`.
