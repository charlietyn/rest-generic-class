# Configuration reference

The package publishes a single configuration file: `config/rest-generic-class.php`.

## Logging

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `logging.rest-generic-class.driver` | string | `single` | Logging driver used when the package creates a channel. |
| `logging.rest-generic-class.path` | string | `storage_path('logs/rest-generic-class.log')` | Log file path. |
| `logging.rest-generic-class.level` | string | `debug` | Log level. |
| `logging.channel.driver` | string | `single` | Secondary channel definition used by the service provider. |
| `logging.channel.path` | string | `storage_path('logs/rest-generic-class.log')` | Secondary channel path. |
| `logging.channel.level` | string | `debug` | Secondary channel level. |
| `logging.query` | bool | `false` | When true, controllers log query actions to `storage/logs/query.log`. |

## Filtering

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `filtering.max_depth` | int | `5` | Maximum nesting depth for `oper` filters. |
| `filtering.max_conditions` | int | `100` | Maximum number of filter conditions per request. |
| `filtering.strict_relations` | bool | `true` | Require `const RELATIONS` on models (recommended). |
| `filtering.allowed_operators` | array | (see config) | Allowed operators for `oper` filtering. |
| `filtering.validate_columns` | bool | `true` | Validate column names before applying filters. |
| `filtering.strict_column_validation` | bool | `true` | Enforce strict column validation. |
| `filtering.column_cache_ttl` | int | `3600` | Cache TTL (seconds) for column lists. |

## Validation

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `validation.cache_enabled` | bool | `true` | Enable/disable caching for validation queries in the `ValidatesExistenceInDatabase` trait. |
| `validation.cache_ttl` | int | `3600` | Cache TTL in seconds for validation queries. |
| `validation.cache_prefix` | string | `'validation'` | Prefix used for validation cache keys. |
| `validation.connection` | string | `'db'` | Database connection name used by validation queries. |

## Optional permission routes

The package can register reusable permission read routes. They are disabled by default so existing applications keep full control over their route table.

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `permissions.routes.enabled` | bool | `false` | When true, loads the package permission routes. |
| `permissions.routes.prefix` | string | `permissions` | Route prefix. Add your app prefix such as `/api` in your route group or HTTP kernel, not here. |
| `permissions.routes.middleware` | array | `['api', 'auth:api']` | Middleware applied to the package routes. |
| `permissions.routes.guard` | string | `api` | Guard used to resolve the authenticated user in the optional package route. It does not filter the payload unless the request also sends `guard`. |

Registered routes when enabled:

```http
GET /permissions
GET /permissions/by-roles
GET /permissions/by-users
```

If the consuming app also defines `apiResource('permissions')`, register the specific routes above before the resource route.

**Next:** [Environment variables](01-env-vars.md)

[Back to documentation index](../index.md)
