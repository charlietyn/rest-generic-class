# Escenarios de caché (ejemplos JSON)

Esta página explica si la estrategia de caché es confiable para variantes reales de request.

## Escenario 1: columnas seleccionadas + relaciones

```json
{
  "select": ["id", "name", "price"],
  "relations": ["category:id,name"]
}
```

Esperado:
- cambios en `select` o `relations` producen keys distintas
- reutilización segura cuando el esquema de request es idéntico

## Escenario 2: filtros avanzados `oper`

```json
{
  "oper": {
    "and": [
      "status|=|active",
      "price|>=|100",
      "stock|>|0"
    ]
  }
}
```

Esperado:
- el árbol de filtros se refleja en la identidad de caché
- árboles equivalentes semánticamente pero distintos textualmente pueden generar keys diferentes (normal si no hay canonicalización profunda)

## Escenario 3: variantes de paginación

Offset:

```json
{
  "pagination": {"page": 2, "pageSize": 25},
  "orderby": [{"id": "desc"}]
}
```

Cursor:

```json
{
  "pagination": {"infinity": true, "pageSize": 25, "cursor": "abc"}
}
```

Esperado:
- cambios de página/cursor generan entradas aisladas de caché

## Escenario 4: modo jerárquico

```json
{
  "hierarchy": {
    "enabled": true,
    "filter_mode": "with_descendants",
    "children_key": "children",
    "max_depth": 3
  }
}
```

Esperado:
- requests con jerarquía y sin jerarquía no colisionan en caché

## Escenario 5: control por request

```json
{
  "cache": false,
  "cache_ttl": 120
}
```

Esperado:
- `cache=false` evita uso de caché
- `cache_ttl` sobrescribe TTL por defecto cuando la caché está habilitada

## ¿Soporta todas las variantes?

Respuesta práctica:
- **Sí, para la mayoría de patrones de request en producción** de este paquete.
- **No está 100% canonicalizado** para toda equivalencia semántica cuando cambia el orden del JSON.

Si los clientes envían payloads equivalentes con mucho cambio de orden, agrega canonicalización antes de hashear el payload de la key.

[Volver al índice de documentación](../index.md)
