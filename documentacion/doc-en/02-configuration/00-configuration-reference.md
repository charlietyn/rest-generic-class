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

## Core authorization (v2.9.0)

Keys under `authorization` (core, independent of any permission package). A `null` value falls back to the
legacy `permissions.authorization.*` key of the same name, then to the default. See the
[authorization contracts](../04-reference/06-authorization-contracts.md).

| Key | Default | Meaning |
| --- | --- | --- |
| `eager_relations` | `null` → `off` | `require_read` makes every to-many relation requested with `?relations=` require one of the relation abilities under the authorized resource, asked to Laravel's Gate. Without a permission integration providing the request scope it fails closed (`MissingAuthorizationContextException`). |
| `eager_relations_to_one` | `null` → `allow` | `allow` exempts to-one relations; `require_read` checks them too. |
| `relation_abilities` | `['read', 'manage']` | Suffixes of the abilities that grant a relation (`<resource>.<relation>.<suffix>`, any of them). |

## Permissions (ronu/rest-permissions)

Since 3.0 the route permissions (channel authorization, permission catalog, permission routes, role/user contracts and their `REST_AUTHZ_*` / `REST_PERMISSIONS_*` variables) live in [`ronu/rest-permissions`](https://github.com/charlietyn/rest-permissions/blob/main/docs/en/configuration.md). The core keeps only `authorization.*`, described above.

**Next:** [Environment variables](01-env-vars.md)

[Back to documentation index](../index.md)
