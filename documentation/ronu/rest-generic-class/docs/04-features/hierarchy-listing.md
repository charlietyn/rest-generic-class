# Hierarchy listing (árboles y jerarquías)

## Overview
`BaseService` soporta listados jerárquicos cuando el modelo define `HIERARCHY_FIELD_ID`. Esto permite construir árboles (parent/child) y aplicar filtros en forma de árbol. 

## When to use / When NOT to use
**Úsalo cuando:**
- Necesitas endpoints para estructuras parent/child (categorías, roles, menús).

**No lo uses cuando:**
- No existe un FK auto-referenciado; en ese caso el modo jerárquico lanzará error.

## How it works
- `BaseModel` declara `HIERARCHY_FIELD_ID` y helpers (`hierarchyParent`, `hierarchyChildren`).
- `BaseService::list_all()` deriva hacia `listHierarchy()` cuando el parámetro `hierarchy` está activo.\n+- Se normaliza la configuración y se aplica un modo de filtrado (`filter_mode`) y profundidad máxima.

## Configuration
No hay claves específicas en config, pero se espera que el modelo defina:
- `HIERARCHY_FIELD_ID`

## Usage examples
```http
GET /api/v1/categories?hierarchy=true
```

```http
GET /api/v1/categories?hierarchy={"enabled":true,"max_depth":3,"children_key":"children"}
```

## Edge cases / pitfalls
- Si `HIERARCHY_FIELD_ID` no está definido, el servicio lanza `HttpException`.
- La profundidad máxima evita ciclos demasiado profundos en árboles grandes.

## Evidence
- File: src/Core/Models/BaseModel.php
  - Symbol: BaseModel::{HIERARCHY_FIELD_ID,hierarchyParent,hierarchyChildren}
  - Notes: contrato de jerarquía y relaciones.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::{list_all,listHierarchy,normalizeHierarchyParams,applyHierarchyFilterMode}
  - Notes: lógica de construcción de árboles.
