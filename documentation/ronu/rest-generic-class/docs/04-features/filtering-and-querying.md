# Filtering & Querying (oper, attr/eq, pagination, orderby)

## Overview
El `BaseService` ofrece filtros dinámicos con `oper`, filtros simples con `attr/eq`, paginación y ordenamiento. Internamente usa `HasDynamicFilter` para interpretar operadores y construir `where`/`whereHas` con validaciones de seguridad. 

## When to use / When NOT to use
**Úsalo cuando:**
- Necesites endpoints de listado con filtros complejos (AND/OR) y operadores.
- Quieras filtrar por relaciones con `oper` anidado.

**No lo uses cuando:**
- Tu consulta requiere lógica específica que no puede expresarse con la sintaxis de `oper`.

## How it works
- `RestController::process_request()` normaliza parámetros (`oper`, `attr`, `eq`, `pagination`, `orderby`).
- `BaseService::list_all()` aplica filtros con `applyOperTree()` y `applyFilters()`.
- `HasDynamicFilter` parsea condiciones `field|operator|value` y aplica prefijos de tabla.
- Los límites de profundidad y número de condiciones se controlan por config.

## Configuration
- `filtering.max_depth`
- `filtering.max_conditions`
- `filtering.allowed_operators`
- `filtering.strict_relations`

## Usage examples
```http
GET /api/v1/orders?oper={"and":["status|=|active","total|>=|100"]}
```

```http
GET /api/v1/orders?attr[status]=active&eq[total]=100
```

```http
GET /api/v1/orders?orderby={"created_at":"desc"}&pagination={"page":1,"pageSize":20}
```

## Edge cases / pitfalls
- Los operadores deben seguir el formato `campo|operador|valor`. Un formato inválido lanza `HttpException(400)`.
- Exceder `max_depth` o `max_conditions` produce error 400.
- Si `strict_relations` está activo, toda relación en filtros debe existir en `RELATIONS`.

## Evidence
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController::process_request()
  - Notes: extracción de `oper`, `attr`, `eq`, `orderby`, `pagination`.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::{list_all,applyOperTree,applyFilters,normalizeOperNode}
  - Notes: orquestación de filtros.
- File: src/Core/Traits/HasDynamicFilter.php
  - Symbol: HasDynamicFilter::{applyFilters,parseConditionString}
  - Notes: operadores y parsing de condiciones.
- File: config/rest-generic-class.php
  - Symbol: filtering config
  - Notes: límites y operadores permitidos.
