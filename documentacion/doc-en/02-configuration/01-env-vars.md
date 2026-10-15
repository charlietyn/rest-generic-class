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

## Channel authorization (v2.6.0)

| Variable | Default | Purpose |
| --- | --- | --- |
| `REST_AUTHZ_ROUTE_PREFIXES` | unset (`null`) | Comma-separated prefixes; whitespace is trimmed. An empty value yields `[]`. |
| `REST_AUTHZ_NAMING` | `derived` | `derived` or `route_name`. |
| `REST_AUTHZ_ON_UNRESOLVED` | `allow` | `allow` or `deny`; use `deny` on protected routes. |
| `REST_AUTHZ_REGISTER_GATE` | `false` | Enable the effective-permission Gate and global union. |
| `REST_AUTHZ_GATE_GUARD` | unset (`null`) | Optional guard filter, for example `api`. |
| `REST_AUTHZ_EXPLICIT_GLOBALS` | `false` | Phase 2: `true` makes only routes marked with the `permission_global` action attribute global; `false` preserves globals for routes without a module. |
| `REST_AUTHZ_CATALOG_MIDDLEWARE` | unset (`null`) | Phase 2: comma-separated middleware markers, aliases or FQCNs. Unset includes all scoped routes; an empty value yields `[]` (no catalog routes). |
| `REST_AUTHZ_EAGER_RELATIONS` | `off` | v2.8.0: `off` or `require_read` (authorize `?relations=` eager loads). |
| `REST_AUTHZ_EAGER_RELATIONS_TO_ONE` | `allow` | v2.8.0: `allow` (to-one relations exempt) or `require_read`. |

Prefix and middleware lists trim whitespace, discard empty entries and retain values such as `0`. `null` and an empty list have different meanings. Example:

```dotenv
REST_AUTHZ_EXPLICIT_GLOBALS=true
REST_AUTHZ_CATALOG_MIDDLEWARE=maybe.permission
```

Refresh cached configuration, then synchronize permissions to apply these options. Changing the environment alone does not update stored restrictions or remove obsolete permissions. See the [configuration reference](00-configuration-reference.md).
