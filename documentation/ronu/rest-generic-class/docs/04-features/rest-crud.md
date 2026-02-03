# Rest CRUD base (RestController + BaseService)

## Overview
El paquete entrega un `RestController` con endpoints estándar (`index`, `show`, `store`, `update`, `destroy`, `updateMultiple`, `deleteById`) y un `BaseService` que encapsula operaciones CRUD, transacciones y manipulación de modelos. 

## When to use / When NOT to use
**Úsalo cuando:**
- Quieras exponer endpoints REST con lógica repetible y consistente.
- Necesites reutilizar un servicio base para múltiples recursos.

**No lo uses cuando:**
- Requieras un flujo altamente custom con reglas de negocio que no encajan en el patrón CRUD.
- Necesites control granular de respuestas sin heredar del controller base.

## How it works
- El controller procesa parámetros de request y delega a `BaseService`.
- Operaciones de escritura (`store`, `update`, `destroy`) se ejecutan dentro de transacciones.
- Se registran errores en el canal `rest-generic-class`.

## Configuration
- Canal de logging `rest-generic-class` y nivel `LOG_LEVEL`.

## Usage examples
```php
// ProductController.php
class ProductController extends RestController
{
    protected $modelClass = Product::class;

    public function __construct(ProductService $service)
    {
        $this->service = $service;
    }
}
```

```php
// ProductService.php
class ProductService extends BaseService
{
    public function __construct()
    {
        parent::__construct(Product::class);
    }
}
```

## Edge cases / pitfalls
- `updateMultiple` espera un payload con la clave `MODEL` del modelo (por ejemplo `product`) para extraer el array de registros.
- `deleteById` espera un array de IDs en el cuerpo del request.
- Errores de DB en `index`/`getOne` se transforman en un error legible vía `DatabaseErrorParser`.

## Evidence
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController::{index,show,store,update,updateMultiple,destroy,deleteById}
  - Notes: endpoints base y transacciones.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::{__construct,create,update,destroy,destroybyid}
  - Notes: operaciones CRUD y uso de modelo.
- File: src/Core/Helpers/DatabaseErrorParser.php
  - Symbol: DatabaseErrorParser::parse()
  - Notes: parseo de errores DB para respuestas legibles.
- File: config/rest-generic-class.php
  - Symbol: logging config
  - Notes: canal de logs utilizado en controller.
