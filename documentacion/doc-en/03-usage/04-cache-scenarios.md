# Cache scenarios (JSON examples)

This page explains whether cache strategy is reliable for real request variants.

## Scenario 1: selected columns + relations

```json
{
  "select": ["id", "name", "price"],
  "relations": ["category:id,name"]
}
```

Expected:
- different `select` or `relations` values produce different cache keys
- safe payload reuse for identical request schema

## Scenario 2: advanced `oper` filters

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

Expected:
- filter tree is reflected in key identity
- textually different but semantically equivalent trees can generate different keys (normal unless deep canonicalization is added)

## Scenario 3: pagination variants

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

Expected:
- page/cursor changes produce isolated cache entries

## Scenario 4: hierarchy mode

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

Expected:
- hierarchy and non-hierarchy requests do not collide in cache

## Scenario 5: request-level control

```json
{
  "cache": false,
  "cache_ttl": 120
}
```

Expected:
- `cache=false` bypasses cache
- `cache_ttl` overrides default TTL when cache is enabled

## Does it handle all variants?

Practical answer:
- **Yes, for most production request patterns** used by this package.
- **Not fully canonicalized** for every semantically equivalent JSON ordering.

If clients send equivalent payloads with many ordering differences, add canonicalization before hashing key payloads.

[Back to documentation index](../index.md)
