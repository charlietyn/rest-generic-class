# Inicio rápido

Este ejemplo muestra un setup REST mínimo usando las clases base del paquete.

## 1) Modelo

```php
<?php

namespace App\Models;

use Ronu\RestGenericClass\Core\Models\BaseModel;

class Product extends BaseModel
{
    protected $fillable = ['name', 'price', 'stock', 'category_id'];

    const MODEL = 'product';
    const RELATIONS = ['category', 'reviews'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
```

## 2) Servicio

```php
<?php

namespace App\Services;

use App\Models\Product;
use Ronu\RestGenericClass\Core\Services\BaseService;

class ProductService extends BaseService
{
    public function __construct()
    {
        parent::__construct(Product::class);
    }
}
```

## 3) Controlador

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Services\ProductService;
use Ronu\RestGenericClass\Core\Controllers\RestController;

class ProductController extends RestController
{
    protected $modelClass = Product::class;

    public function __construct(ProductService $service)
    {
        $this->service = $service;
    }
}
```

## 4) Rutas

```php
use App\Http\Controllers\Api\ProductController;

Route::prefix('v1')->group(function () {
    Route::apiResource('products', ProductController::class);
    Route::post('products/update-multiple', [ProductController::class, 'updateMultiple']);
});
```

## 5) Probar una petición

```http
GET /api/v1/products?select=["id","name"]&relations=["category:id,name"]
```

**Siguiente:** [Referencia de configuración](../02-configuration/00-configuration-reference.md)

[Volver al índice de documentación](../index.md)
