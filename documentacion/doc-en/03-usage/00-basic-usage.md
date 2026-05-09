# Basic usage

This section covers the most common REST operations and query parameters.

## Listing records

```http
GET /api/v1/products?select=["id","name"]&relations=["category:id,name"]
```

### Filters with `oper`

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

### Legacy equality filters

```json
{
  "attr": {
    "status": "active",
    "category_id": 3
  }
}
```

## Sorting

```json
{
  "orderby": [
    {"price": "desc"},
    {"created_at": "asc"}
  ]
}
```

You can also sort by **fields of related entities** using dot notation. The relation must be declared in `const RELATIONS` on the model:

```json
{
  "orderby": [
    {"user.name": "asc"},
    {"category.parent.name": "desc"}
  ]
}
```

The package translates each dotted entry into a scalar ordering subquery, so it works for `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, polymorphic and `hasManyThrough` relations without producing duplicate rows. See [Sorting by related fields](01-advanced-usage.md#sorting-by-related-fields) for details and edge cases.

## Pagination

```json
{
  "pagination": {
    "page": 1,
    "pageSize": 25
  }
}
```

## Create and update

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

## Delete

```http
DELETE /api/v1/products/10
```

[Back to documentation index](../index.md)

## Evidence
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController::process_request(), RestController::index(), RestController::store(), RestController::update(), RestController::destroy()
  - Notes: Shows the request parameter extraction and CRUD endpoints that forward to the service.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::process_query(), BaseService::list_all(), BaseService::create(), BaseService::update(), BaseService::destroy()
  - Notes: Confirms the query parameters and CRUD behavior used in the examples.
