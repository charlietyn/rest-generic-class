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

`eq` is an alias of `attr`. When both arrive they are decoded (each may come as JSON in the query
string) and merged; **`attr` wins a repeated key**:

```http
GET /api/v1/products?eq={"status":"draft","category_id":3}&attr={"status":"active"}
```

filters by `status = active AND category_id = 3`. The same rule applies to the aggregate path, to
relation endpoints and to the NestJS libraries (the same rule in every backend).

## Query rules (v3.1.0)

A query the library cannot apply as written is a **400** with a clear message, never a 500 (which
also echoed the SQL) nor a 200 that ignored part of the request. The messages are the ones the NestJS
libraries answer.

| Case | Response | Switch |
| --- | --- | --- |
| Unknown column in `oper`, `attr`/`eq`, `select` or `orderby` (also `other_table.col`, `name) OR (1=1`, `count(*)`) | 400 `Column 'nope' does not exist on products.` | `filtering.strict_column_validation` |
| Hidden column (`$hidden`, timestamps included) in `oper`, `attr`/`eq` or `orderby`, also through relations and on relation endpoints | 400 `Column 'password' of User cannot be used to filter.` (or `… to sort.`) | `filtering.allow_hidden_columns` |
| Hidden column in `select` | silently dropped; when none is left, the visible row is returned | `filtering.allow_hidden_columns` |
| `oper`, `attr`, `eq`, `orderby` or `pagination` with invalid JSON | 400 `The oper parameter is not valid JSON.` | `filtering.reject_invalid_json` |
| More than `filtering.max_depth` (5) levels: the root counts 1; each nested `and`/`or` group and each relation hop, 1 more | 400 `Maximum nesting depth (5) exceeded.` | `filtering.max_depth` |
| `regexp` / `not regexp` | `~` / `!~` on PostgreSQL, `REGEXP` on MySQL/MariaDB; 400 `The regexp operator is not supported on the sqlite driver.` on SQLite and SQL Server | `filtering.translate_regexp` |
| `exists` / `not exists` | 400 `The exists value is not a valid operator.` (removed in 3.1: they always answered 500) | — |

`exists` could not mean anything: a condition string can only name a column, and `whereExists` needs a
sub-query. "Has related rows" is a relation filter:

```json
{ "oper": { "devices": { "and": ["id|>|0"] } } }
```

Supported operators: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `like`, `not like`, `ilike`, `ilikeu`,
`not ilike`, `in`, `not in`, `between`, `not between`, `date`, `not date`, `null`, `not null`, `regexp`,
`not regexp`.

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
