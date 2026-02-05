# Uso básico

Esta sección cubre las operaciones REST más comunes y los parámetros de consulta.

## Listar registros

```http
GET /api/v1/products?select=["id","name"]&relations=["category:id,name"]
```

### Filtros con `oper`

```json
{
  "oper": {
    "and": [
      "status|=|active",
      "price|>=|100"
    ]
  }
}
```

### Filtros de igualdad heredados

```json
{
  "attr": {
    "status": "active",
    "category_id": 3
  }
}
```

## Ordenamiento

```json
{
  "orderby": [
    {"price": "desc"},
    {"created_at": "asc"}
  ]
}
```

## Paginación

```json
{
  "pagination": {
    "page": 1,
    "pageSize": 25
  }
}
```

## Crear y actualizar

```http
POST /api/v1/products
Content-Type: application/json

{
  "name": "Keyboard",
  "price": 89.99,
  "stock": 100
}
```

```http
PUT /api/v1/products/10
Content-Type: application/json

{
  "price": 79.99
}
```

## Eliminar

```http
DELETE /api/v1/products/10
```

[Volver al índice de documentación](../index.md)

## Evidencia
- Archivo: src/Core/Controllers/RestController.php
  - Símbolo: RestController::process_request(), RestController::index(), RestController::store(), RestController::update(), RestController::destroy()
  - Notas: Muestra la extracción de parámetros de la solicitud y los endpoints CRUD que delegan en el servicio.
- Archivo: src/Core/Services/BaseService.php
  - Símbolo: BaseService::process_query(), BaseService::list_all(), BaseService::create(), BaseService::update(), BaseService::destroy()
  - Notas: Confirma los parámetros de consulta y el comportamiento CRUD usados en los ejemplos.
