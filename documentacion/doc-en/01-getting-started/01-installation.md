# Installation

Install the package via Composer:

```bash
composer require ronu/rest-generic-class
```

Laravel package auto-discovery registers the service provider automatically.

## Upgrading a Laravel 12 application to Laravel 13

Upgrade the host application to PHP 8.3 or newer, then update both Laravel and
this package together:

```bash
composer require laravel/framework:^13.12 ronu/rest-generic-class --with-all-dependencies
php artisan optimize:clear
```

If package caching is enabled, review the
[Laravel 13 object-cache note](../02-configuration/03-cache-strategy.md#laravel-13-and-serialized-object-cache).
The package's `rgc:v2` cache namespace prevents old `rgc:v1` entries from being reused.

## Publish configuration (optional)

```bash
php artisan vendor:publish --tag=rest-generic-class-config
```

This publishes `config/rest-generic-class.php` into your application.

**Next:** [Quickstart](02-quickstart.md)

[Back to documentation index](../index.md)
