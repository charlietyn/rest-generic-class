# Findings

## High

### F-001: Uso de `env()` en runtime (LOG_QUERY) rompe `config:cache`
- **Evidence**
  - File: src/Core/Controllers/RestController.php
  - Symbol: RestController::callAction
  - Snippet:
    ```php
    $log = env('LOG_QUERY', false);
    if ($log)
        File::append(
    ```
  - Reason: `env()` en runtime no es seguro con `config:cache`.
- **Impact**: Comportamiento inconsistente en producción y dificultad para cachear configuración.
- **Proposed fix**: Añadir `rest-generic-class.logging.query` en config y usar `config()` en runtime.

### F-002: Fallback de `env()` en ServiceProvider (LOG_LEVEL)
- **Evidence**
  - File: src/Core/Providers/RestGenericClassServiceProvider.php
  - Symbol: RestGenericClassServiceProvider::boot
  - Snippet:
    ```php
    'level' => config('rest-generic-class.logging.channel.level', env('LOG_LEVEL', 'debug')),
    ```
  - Reason: `env()` en runtime dentro del ServiceProvider.
- **Impact**: `config:cache` puede ignorar el env value en producción.
- **Proposed fix**: Mover el default al archivo config y usar `config()` sin `env()` aquí.

## Medium

### F-003: Config keys leídas pero no declaradas (`rest-generic-class.logging.channel.*`)
- **Evidence**
  - File: src/Core/Providers/RestGenericClassServiceProvider.php
  - Symbol: RestGenericClassServiceProvider::boot
  - Snippet:
    ```php
    'driver' => config('rest-generic-class.logging.channel.driver', 'single'),
    'path' => config('rest-generic-class.logging.channel.path', storage_path('logs/rest-generic-class.log')),
    ```
  - Reason: Keys `rest-generic-class.logging.channel.*` no existen en `config/rest-generic-class.php`.
- **Impact**: Defaults en runtime pueden divergir de lo configurado/documentado.
- **Proposed fix**: Declarar `logging.channel.*` en `config/rest-generic-class.php` (o renombrar el ServiceProvider a `logging.rest-generic-class.*`).

### F-004: ENV documentadas pero no usadas (`REST_MAX_DEPTH`, `REST_MAX_CONDITIONS`, `REST_STRICT_RELATIONS`)
- **Evidence**
  - File: docs/Documentation.md
  - Symbol: Configuration snippet
  - Snippet:
    ```php
    'max_depth' => env('REST_MAX_DEPTH', 5),
    'max_conditions' => env('REST_MAX_CONDITIONS', 100),
    'strict_relations' => env('REST_STRICT_RELATIONS', true),
    ```
  - Reason: En `config/rest-generic-class.php` esos valores son constantes y no usan env.
- **Impact**: Variables sin efecto real; confusión en usuarios y entornos.
- **Proposed fix**: Ajustar docs o implementar esas ENV en config (decisión de producto).

## Low / Needs manual confirm

### F-005: Config keys declaradas pero no leídas en el paquete
- **Evidence**
  - File: config/rest-generic-class.php
  - Symbol: return array
  - Snippet:
    ```php
    'allowed_operators' => ['=', '!=', '<', '>', '<=', '>=', 'like', 'not like',
    'validate_columns' => env('REST_VALIDATE_COLUMNS', true),
    'strict_column_validation' => env('REST_STRICT_COLUMNS', true),
    'column_cache_ttl' => 3600,
    ```
  - Reason: No se encontraron callsites en `src/` (revisar uso indirecto por consumers).
- **Impact**: Posible deuda de configuración o features incompletas.
- **Proposed fix**: Confirmar con maintainers; si no hay uso, documentar como deprecated o remover.
