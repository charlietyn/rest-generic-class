# Environment variables

The package reads environment variables only from the configuration file. This is safe for config caching.

| Variable | Default | Used for |
| --- | --- | --- |
| `LOG_LEVEL` | `debug` | Sets the log level for the package logging channel. |
| `LOG_QUERY` | `false` | Enables query logging in controllers when `true`. |
| `REST_VALIDATE_COLUMNS` | `true` | Enables column validation for filtering. |
| `REST_STRICT_COLUMNS` | `true` | Enables strict column validation behavior. |
| `REST_VALIDATION_CACHE_ENABLED` | `true` | Enable/disable caching for database validation queries (`ValidatesExistenceInDatabase` trait). |
| `REST_VALIDATION_CACHE_TTL` | `3600` | Cache TTL in seconds for validation queries. |
| `REST_VALIDATION_CACHE_PREFIX` | `'validation'` | Prefix for validation cache keys. |
| `REST_VALIDATION_CONNECTION` | `'db'` | Database connection name used by validation queries. |
| `REST_PERMISSIONS_ROLES_RELATION` | `roles` | Default name of the User model relation that returns its roles. Each model can override it with `const ROLES_RELATION`. |
| `REST_PERMISSIONS_ROUTES_ENABLED` | `false` | Enables package-provided permission read routes. |
| `REST_PERMISSIONS_ROUTES_PREFIX` | `permissions` | Prefix for optional permission routes. |
| `REST_PERMISSIONS_ROUTES_MIDDLEWARE` | `api,auth:api` | Comma-separated middleware list for optional permission routes. |
| `REST_PERMISSIONS_ROUTES_GUARD` | `api` | Guard used to resolve the authenticated user in optional permission routes. |
| `REST_PERMISSIONS_USER_MODEL` | _(unset)_ | User model FQCN used for early boot validation of the `ProvidesRoles` contract. Optional; when unset the validation runs lazily at runtime. |
| `REST_PERMISSIONS_ROLE_MODEL` | _(unset)_ | Role model FQCN used for early boot validation of the `ProvidesRolePermissions` contract. Optional; when unset the validation runs lazily at runtime. |

**Next:** [Cache strategy](03-cache-strategy.md)

[Back to documentation index](../index.md)
