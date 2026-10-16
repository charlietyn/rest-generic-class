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
- **ronu/rest-permissions** (since 3.0): route permissions — permission models, traits and middleware, on top of spatie/laravel-permission.

### Supported spatie/laravel-permission lines (rest-generic-class 2.8.1–2.9; since 3.0 see ronu/rest-permissions)

| Spatie | Laravel 12 | Laravel 13 | PHP |
| --- | --- | --- | --- |
| 6.21+ | yes | 6.25+ | 8.2+ (8.3+ on Laravel 13) |
| 7.x | yes | 7.2.4+ | 8.3+ (7.0.x and 7.2.4 require 8.4) |
| 8.x | yes | yes | 8.3+ |

In 2.8.1–2.9 Composer declared `conflict: spatie/laravel-permission <6.21 || >=9.0` here. Since 3.0 this
package has no Spatie dependency or conflict: `ronu/rest-permissions` requires these lines and tests each
row in its CI.

**Next:** [Installation](01-installation.md)

[Back to documentation index](../index.md)
