# Middleware

## TransformData

`Ronu\RestGenericClass\Core\Middleware\TransformData` validates POST/PUT/PATCH requests by instantiating a `BaseFormRequest` class and running `validate_request()`.

## InjectRequestParams

`Ronu\RestGenericClass\Core\Middleware\InjectRequestParams` injects key/value pairs into the request from middleware parameters. It supports:

- Namespacing (`ns=_meta`).
- Force overwrite (`force`).
- Type casting (booleans, numbers, JSON, base64, null).

## SpatieAuthorize

`Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize` derives permission names from routes and checks them with Spatie permission cache. It supports module-aware permissions via `nwidart/laravel-modules`.

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
