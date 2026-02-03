# ronu/rest-generic-class - Production Documentation (Laravel 12)

## ¿Qué es y qué resuelve?
`ronu/rest-generic-class` es una librería Laravel que provee una base reusable para construir APIs REST sin repetir CRUD, filtrado dinámico, selección de campos y carga de relaciones de forma consistente. Está diseñada para extenderse mediante `BaseModel`, `BaseService` y `RestController`, de modo que tus módulos de negocio hereden comportamiento estándar sin reimplementar lógica de consulta y validación. 

## Quickstart (mínimo viable)
### 1) Instalar el paquete
```bash
composer require ronu/rest-generic-class
```

### 2) Publicar configuración (opcional)
```bash
php artisan vendor:publish --tag=rest-generic-class-config
```

### 3) Modelo base
```php
<?php

namespace App\Models;

use Ronu\RestGenericClass\Core\Models\BaseModel;

class Product extends BaseModel
{
    protected $fillable = ['name', 'price', 'category_id'];

    const MODEL = 'product';
    const RELATIONS = ['category'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
```

### 4) Servicio base
```php
<?php

namespace App\Services;

use Ronu\RestGenericClass\Core\Services\BaseService;
use App\Models\Product;

class ProductService extends BaseService
{
    public function __construct()
    {
        parent::__construct(Product::class);
    }
}
```

### 5) Controller REST
```php
<?php

namespace App\Http\Controllers\Api;

use Ronu\RestGenericClass\Core\Controllers\RestController;
use App\Services\ProductService;
use App\Models\Product;

class ProductController extends RestController
{
    protected $modelClass = Product::class;

    public function __construct(ProductService $service)
    {
        $this->service = $service;
    }
}
```

### 6) Rutas
```php
<?php

use App\Http\Controllers\Api\ProductController;

Route::prefix('v1')->group(function () {
    Route::apiResource('products', ProductController::class);
    Route::post('products/update-multiple', [ProductController::class, 'updateMultiple']);
});
```

## Índice
- [Installation](docs/01-installation.md)
- [Configuration](docs/02-configuration.md)
- [Architecture](docs/03-architecture.md)
- [Features](docs/04-features/)
- [Security](docs/05-security.md)
- [Testing](docs/06-testing.md)
- [Troubleshooting](docs/07-troubleshooting.md)
- [FAQ](docs/08-faq.md)
- [Reference](docs/09-reference/)
- [Migration Guide](docs/10-migration-guide.md)
- [Contributing](docs/11-contributing.md)
- [License](docs/12-license.md)

## Requisitos
- PHP `^8.0`.
- Laravel 12 (`illuminate/*` ^12.0). 

## Common integration paths
- **CRUD REST con filtros avanzados** usando `RestController` + `BaseService`.
- **Protección por permisos** usando `SpatieAuthorize` y los traits de permisos.
- **Auto-carga de relaciones y selección de campos** con `relations` y `select`.
- **Validación centralizada** con `BaseFormRequest` + middleware `TransformData`.

## Evidence
- File: composer.json
  - Symbol: name/require/extra.laravel.providers
  - Notes: requisitos PHP/Laravel y auto-discovery del Service Provider.
- File: src/Core/Providers/RestGenericClassServiceProvider.php
  - Symbol: RestGenericClassServiceProvider::register()/boot()
  - Notes: publish de config y canal de logging.
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController::{index,store,update,updateMultiple,show,destroy}
  - Notes: endpoints REST base utilizados en el quickstart.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::__construct()
  - Notes: inicialización del servicio con el modelo base.
