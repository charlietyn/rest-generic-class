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

`eq` es un alias de `attr`. Si llegan los dos, se decodifican (cada uno puede venir como JSON en la
query string) y se fusionan; **en una clave repetida gana `attr`**:

```http
GET /api/v1/products?eq={"status":"draft","category_id":3}&attr={"status":"active"}
```

filtra por `status = active AND category_id = 3`. Es la misma regla en el camino de agregaciones, en los
endpoints de relación y en las librerías de NestJS (la misma regla en todos los backends).

## Reglas de la consulta (v3.1.0)

Una consulta que la librería no puede aplicar tal como está escrita es un **400** con un mensaje claro,
nunca un 500 (que además devolvía el SQL) ni un 200 que ignoró parte de la petición. Los mensajes son los
mismos que dan las librerías de NestJS.

| Caso | Respuesta | Interruptor |
| --- | --- | --- |
| Columna inexistente en `oper`, `attr`/`eq`, `select` u `orderby` (también `otra_tabla.col`, `name) OR (1=1`, `count(*)`) | 400 `Column 'nope' does not exist on products.` | `filtering.strict_column_validation` |
| Columna oculta (`$hidden`, timestamps incluidos) en `oper`, `attr`/`eq` u `orderby`, también a través de relaciones y en los endpoints de relación | 400 `Column 'password' of User cannot be used to filter.` (o `… to sort.`) | `filtering.allow_hidden_columns` |
| Columna oculta en `select` | se omite en silencio; si no queda ninguna, se devuelve la fila visible | `filtering.allow_hidden_columns` |
| `oper`, `attr`, `eq`, `orderby` o `pagination` con JSON inválido | 400 `The oper parameter is not valid JSON.` | `filtering.reject_invalid_json` |
| Más de `filtering.max_depth` (5) niveles: la raíz cuenta 1; cada grupo `and`/`or` anidado y cada salto de relación, 1 más | 400 `Maximum nesting depth (5) exceeded.` | `filtering.max_depth` |
| `regexp` / `not regexp` | `~` / `!~` en PostgreSQL, `REGEXP` en MySQL/MariaDB; 400 `The regexp operator is not supported on the sqlite driver.` en SQLite y SQL Server | `filtering.translate_regexp` |
| `exists` / `not exists` | 400 `The exists value is not a valid operator.` (retirados en 3.1: siempre daban un 500) | — |

`exists` no tenía significado posible: una condición en texto solo puede nombrar una columna, y
`whereExists` necesita una subconsulta. «Tiene filas relacionadas» se expresa con un filtro por
relación:

```json
{ "oper": { "devices": { "and": ["id|>|0"] } } }
```

Operadores admitidos: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `like`, `not like`, `ilike`, `ilikeu`,
`not ilike`, `in`, `not in`, `between`, `not between`, `date`, `not date`, `null`, `not null`, `regexp`,
`not regexp`.

## Ordenamiento

```json
{
  "orderby": [
    {"price": "desc"},
    {"created_at": "asc"}
  ]
}
```

También puedes ordenar por **campos de entidades relacionadas** usando notación de punto. La relación debe estar declarada en `const RELATIONS` del modelo:

```json
{
  "orderby": [
    {"user.name": "asc"},
    {"category.parent.name": "desc"}
  ]
}
```

El paquete traduce cada entrada con punto en un subquery escalar de ordenamiento, por lo que funciona uniformemente para `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, polimórficas y `hasManyThrough` sin generar filas duplicadas. Ver [Ordenamiento por campos de relaciones](01-advanced-usage.md#ordenamiento-por-campos-de-relaciones) para detalles y casos extremos.

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
