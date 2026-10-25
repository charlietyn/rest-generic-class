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
| `filtering.max_depth` | int | `5` | Maximum depth of `oper`: the root counts 1 and each relation hop and each nested `and`/`or` group adds 1 (groups too since 3.1). Beyond it: 400 "Maximum nesting depth (5) exceeded." |
| `filtering.max_conditions` | int | `100` | Maximum number of filter conditions per request. |
| `filtering.strict_relations` | bool | `true` | Require `const RELATIONS` on models (recommended). |
| `filtering.allowed_operators` | array | (see config) | Informational: the filter validates against its own list (see [Basic usage](../03-usage/00-basic-usage.md#query-rules-v310)). No `exists`/`not exists` since 3.1. |
| `filtering.validate_columns` | bool | `true` | Reserved: no effect today. |
| `filtering.strict_column_validation` | bool | `true` | v3.1: a column the table does not have (or another table's, or an expression) is a 400 "Column '<col>' does not exist on <table>." in `oper`, `attr`/`eq`, `select` and `orderby`, relation endpoints included. `false`: it reaches the database as before. |
| `filtering.allow_hidden_columns` | bool | `false` | v3.1: with `false`, a `$hidden` column (timestamps included) is a 400 "Column '<col>' of <Model> cannot be used to filter\|sort." and is silently dropped from `select`. `true`: previous behaviour. |
| `filtering.reject_invalid_json` | bool | `true` | v3.1: `oper`, `attr`, `eq`, `orderby` or `pagination` with invalid JSON is a 400 "The <param> parameter is not valid JSON.". `false`: ignored as before. |
| `filtering.translate_regexp` | bool | `true` | v3.1: `regexp`/`not regexp` → `~`/`!~` on PostgreSQL, `REGEXP` on MySQL/MariaDB, 400 on SQLite and SQL Server. `false`: the operator is forwarded verbatim. |
| `filtering.column_cache_ttl` | int | `3600` | Reserved: the column listing is read once per connection and table for the life of the process. |

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
