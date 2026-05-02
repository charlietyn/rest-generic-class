# Testing

The package includes a lightweight PHPUnit harness for pure unit tests. It intentionally avoids Testbench, Spatie database fixtures, and a host Laravel app.

Run package tests from the package root:

```bash
composer install
vendor/bin/phpunit
```

Current package tests cover:

- `PermissionCompressor` wildcard behavior and edge cases.
- Authenticated permission payload behavior for flat vs. compressed responses.

Recommended checks for your host app:

- Feature tests for CRUD endpoints using your `RestController` subclasses.
- Tests for `oper` filtering and relation allowlist enforcement.
- Tests for hierarchy listing if you use `HIERARCHY_FIELD_ID`.
- Route tests for optional permission routes if `REST_PERMISSIONS_ROUTES_ENABLED=true`.

[Back to documentation index](../index.md)
