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
| `permissions.roles_relation` | string | `roles` | Name of the Eloquent relation on the User model that returns its roles. Global fallback; each model can override it with `const ROLES_RELATION`. Supports one-to-many (single role) and many-to-many (several roles) cardinality. |
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

## Permission contracts (User and Role)

Starting in 3.0.0 the package requires the integrator to declare **formal contracts** on their User and Role models (see the [detailed guide](../03-usage/06-permissions.md)). This config section is **optional** and only enables the early boot-time validation; the contracts are always enforced at runtime, even if these keys are left empty.

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `permissions.contracts.user_model` | string\|null | `null` | FQCN of the User model. When set, the `ServiceProvider` verifies in `boot()` that the class implements `Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles`. |
| `permissions.contracts.role_model` | string\|null | `null` | FQCN of the Role model. When set, the `ServiceProvider` verifies in `boot()` that the class implements `Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions`. |

If any of these classes does not implement the expected interface, the application fails to boot with a `RolesContractViolationException` (actionable message stating exactly which class must implement which interface).

```php
// Example
'permissions' => [
    'contracts' => [
        'user_model' => \App\Models\User::class,
        'role_model' => \App\Models\Role::class,
    ],
],
```

**Next:** [Environment variables](01-env-vars.md)

[Back to documentation index](../index.md)

## Channel authorization (v2.6.0)

All keys below live under `permissions.authorization`.

| Key | Default | Meaning |
| --- | --- | --- |
| `route_prefixes` | `null` | Legacy: match the Laravel group prefix starting with the guard name. An array matches URI prefixes on segment boundaries (`admin` never matches `administration`); `[]` matches nothing. Nested prefixes such as `api/admin` are supported; the first matching prefix wins. |
| `naming` | `derived` | Infer `{version}.{module}.{model}.{action}` (optional version/module). `route_name` uses the exact route name, including `store`/`destroy`, without mapping or version prepending; unnamed routes cannot resolve. |
| `on_unresolved` | `allow` | `allow` continues without checking a permission. `deny` returns 403 for every unresolved route carrying the middleware, including routes outside the configured prefixes. |
| `register_gate` | `false` | Register an optional `Gate::before` for users exposing `effectivePermissions()` without Spatie's `checkPermissionTo()`. Also include global permissions in the trait's effective union. |
| `gate_guard` | `null` | Filter the optional Gate's effective permissions by this guard; `null` accepts all guards. Use `api` when the authentication guard is `api`. |

`route_prefixes` controls route selection; it does not define authentication guards. The middleware guard parameter and sync command `--guard` must remain real guard names. The obsolete `route-permissions` configuration key is no longer read. `passesFilters()` remains an overridable hook, with no built-in filter rules.

The `allow` default is retained for compatibility but deprecated and unsafe on protected routes; migration to `deny` is planned for v3.0.0.

```php
// config/rest-generic-class.php -> permissions.authorization
'authorization' => [
    'route_prefixes' => ['admin', 'client', 'mobile'],
    'naming' => 'route_name',
    'on_unresolved' => 'deny',
    'register_gate' => true,
    'gate_guard' => 'api',
],
```
