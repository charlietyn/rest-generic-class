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

**Next:** [Installation](01-installation.md)

[Back to documentation index](../index.md)
