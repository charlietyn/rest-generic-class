# Requirements

## Runtime requirements

| Laravel | PHP |
| --- | --- |
| 12.61.1+ | 8.2+ |
| 13.12+ | 8.3+ |

Composer enforces the compatible pair through `laravel/framework: ^12.61.1 || ^13.12.0`.
PHP 8.2 installations resolve Laravel 12; Laravel 13 requires PHP 8.3 or newer.

## Optional integrations

These packages are optional and only needed when you use the related features:

- **maatwebsite/excel**: required for `exportExcel()`.
- **barryvdh/laravel-dompdf**: required for `exportPdf()`.
- **nwidart/laravel-modules**: required when using module-aware permissions.
- **spatie/laravel-permission**: required for permission models, traits, and middleware.

### Supported spatie/laravel-permission lines

| Spatie | Laravel 12 | Laravel 13 | PHP |
| --- | --- | --- | --- |
| 6.21+ | yes | 6.25+ | 8.2+ (8.3+ on Laravel 13) |
| 7.x | yes | 7.2.4+ | 8.3+ (7.0.x and 7.2.4 require 8.4) |
| 8.x | yes | yes | 8.3+ |

Composer declares `conflict: spatie/laravel-permission <6.21 || >=9.0`: installing an untested line
fails at resolution time instead of at `package:discover`. Spatie stays optional — applications that
do not use it are not forced to install it. Each row is tested in CI (since v2.8.1).

**Next:** [Installation](01-installation.md)

[Back to documentation index](../index.md)
