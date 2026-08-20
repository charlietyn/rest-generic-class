# Testing

The package uses PHPUnit plus Orchestra Testbench. Most tests remain focused unit
tests; the provider compatibility test boots the package in a real Laravel
application container.

Run package tests from the package root:

```bash
composer install
vendor/bin/phpunit
```

The CI matrix covers Laravel 12 on PHP 8.2/8.3 and Laravel 13 on PHP 8.3/8.4/8.5.
It exercises CRUD support, Eloquent relations, pagination, soft deletes,
validation, authorization, permissions, cache behavior, and package-provider boot.

Recommended checks for your host app:

- Feature tests for CRUD endpoints using your `RestController` subclasses.
- Tests for `oper` filtering and relation allowlist enforcement.
- Tests for hierarchy listing if you use `HIERARCHY_FIELD_ID`.
- Route tests for optional permission routes if `REST_PERMISSIONS_ROUTES_ENABLED=true`.

[Back to documentation index](../index.md)
