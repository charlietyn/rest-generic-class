## REST GENERIC CLASS

Base classes and helpers to accelerate RESTful APIs in Laravel with consistent filtering,
validation workflows, and optional permission tooling.

## Installation

```bash
composer require ronu/rest-generic-class
```

## Quick Start

### Controller + Service
```php
use Ronu\RestGenericClass\Core\Controllers\RestController;
use Ronu\RestGenericClass\Core\Services\BaseService;

class UsersController extends RestController
{
    protected $modelClass = User::class;

    public function __construct()
    {
        $this->service = new BaseService($this->modelClass);
    }
}
```

## Filtering Guide

### Basic filtering with `oper`
```json
{
  "oper": [
    "rating|<|1",
    "zip_code|=|0"
  ]
}
```

### Logical groups with `and` / `or`
```json
{
  "oper": {
    "and": [
      "status|=|active",
      "created_at|>=|2024-01-01"
    ],
    "or": [
      "type|=|premium",
      "type|=|trial"
    ]
  }
}
```

### Relation-scoped filtering
```json
{
  "relations": ["users"],
  "oper": {
    "and": [
      "status|=|active"
    ],
    "users": {
      "and": [
        "name|like|%john%"
      ]
    }
  }
}
```

### Nested relation example
```json
{
  "relations": ["users.roles"],
  "oper": {
    "users.roles": {
      "and": [
        "name|=|admin"
      ]
    }
  }
}
```

## Dependencies
This package relies on Laravel components and optional packages for Excel/PDF/modules/permissions.
Ensure your application includes:
- `illuminate/*` components (database, support, validation, http, pagination, mail)
- Optional: `maatwebsite/excel`, `barryvdh/laravel-dompdf`, `nwidart/laravel-modules`, `spatie/laravel-permission`

## Logging
The package registers a `rest-generic-class` logging channel (if not already configured)
that writes to `storage/logs/rest-generic-class.log`. To customize it, publish the package config:

```bash
php artisan vendor:publish --tag=rest-generic-class-config
```

Edit the published config at `config/rest-generic-class.php` to adjust channel driver,
path, or level. You can also override the channel directly in `config/logging.php`.

## License
This library is open-sourced software licensed under the MIT license.
