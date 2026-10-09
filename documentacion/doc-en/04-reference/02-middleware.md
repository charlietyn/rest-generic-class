# Middleware

## TransformData

`Ronu\RestGenericClass\Core\Middleware\TransformData` validates POST/PUT/PATCH requests by instantiating a `BaseFormRequest` class and running `validate_request()`.

## InjectRequestParams

`Ronu\RestGenericClass\Core\Middleware\InjectRequestParams` injects key/value pairs into the request from middleware parameters. It supports:

- Namespacing (`ns=_meta`).
- Force overwrite (`force`).
- Type casting (booleans, numbers, JSON, base64, null).

## SpatieAuthorize

`SpatieAuthorize` resolves permissions and checks them with `$user->can()`.

[Back to documentation index](../index.md)

## Evidence
- File: src/Core/Middleware/TransformData.php
  - Symbol: TransformData::handle()
  - Notes: Validates request data via `BaseFormRequest`.
- File: src/Core/Middleware/InjectRequestParams.php
  - Symbol: InjectRequestParams::handle(), InjectRequestParams::castValue()
  - Notes: Injects and casts request parameters.
- File: src/Core/Middleware/SpatieAuthorize.php
  - Symbol: SpatieAuthorize::handle()
  - Notes: Derives permissions and enforces authorization.

## Channel authorization (v2.6.0)

The middleware and permission synchronization now share `RouteMetaResolver`. Authorization uses `$user->can($canonicalName)` with Spatie or the optional effective-permission Gate. Register authentication before this middleware, for example `auth:api` followed by your alias `auto.authorize:api`.

**Security:** with legacy settings, a route prefix different from the guard leaves the route unresolved and `on_unresolved=allow` skips authorization. Set explicit channel prefixes and `deny` for these applications. Missing users or missing resolved permissions return 403; unresolved routes also return 403 in `deny` mode. Debug mode includes the required permission or the unresolved-route reason; production only returns `Forbidden`.

Named closures are supported in `route_name` mode, including names ending in `.create` or `.edit`. Derived mode preserves catalog exclusions for UI create/edit actions and routes without controllers. Version metadata is now honored by the middleware as well as synchronization: resynchronize and review grants when using versioned routes.

See [configuration](../02-configuration/00-configuration-reference.md) for all defaults and [channel deployment](../03-usage/03-edge-and-extreme-scenarios.md) for the safe activation order.
