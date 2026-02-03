# Relaciones y selección de campos

## Overview
El paquete permite cargar relaciones y seleccionar campos específicos mediante los parámetros `relations` y `select`, con soporte para relaciones anidadas y filtros por relación. 

## When to use / When NOT to use
**Úsalo cuando:**
- Necesites controlar el payload de respuesta (`select`).
- Quieras eager load de relaciones con campos específicos.

**No lo uses cuando:**
- Necesitas relaciones dinámicas sin validar (si `strict_relations` está activo debes declarar `RELATIONS`).

## How it works
- `relations` se normaliza en `BaseService::normalizeRelations()`.
- Si `relations` incluye `all`, se usan las relaciones permitidas del modelo.
- Se valida que la relación esté en `RELATIONS` (o se auto-detecta si `strict_relations` es `false`).
- Para filtros anidados, `applyOperTree()` aplica `whereHas` sobre relaciones.

## Configuration
- `filtering.strict_relations`

## Usage examples
```http
GET /api/v1/products?relations=["category","reviews"]
```

```http
GET /api/v1/products?relations=["category:id,name","reviews:id,rating"]
```

```http
GET /api/v1/products?select=["id","name","price"]
```

## Edge cases / pitfalls
- Si una relación no está en `RELATIONS` y `strict_relations` está habilitado, se lanza `HttpException(400)`.
- Para relaciones anidadas, se valida el segmento base (ej. `user.roles` valida `user`).

## Evidence
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::{relations,normalizeRelations,extractRelationFiltersForModel,getRelationsForModel}
  - Notes: validación y procesamiento de relaciones.
- File: src/Core/Models/BaseModel.php
  - Symbol: BaseModel::RELATIONS
  - Notes: whitelist de relaciones permitidas.
- File: config/rest-generic-class.php
  - Symbol: filtering.strict_relations
  - Notes: controla la validación de relaciones.
