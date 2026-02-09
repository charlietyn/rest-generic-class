# Cache Scenarios & Compatibility Matrix (Laravel 12)

## Purpose

This document explains, in practical terms, how the current package cache strategy behaves with real request JSON payloads and query variants.

It is intended to help teams decide whether the current approach is enough for production traffic, and where to add hardening.

---

## 1) What is included in the cache decision and key

Current cache logic for read operations (`list_all`, `get_one`) uses:

- operation name
- model class
- route/path + HTTP method
- request query params
- configured vary headers
- authenticated user ID
- normalized service params array
- model cache version

This means the cache is **request-aware** and generally safe for multi-tenant or multi-locale APIs when headers are configured correctly.

---

## 2) Scenarios with request payloads

## A) Selected columns (`select`)

```json
{
  "select": ["id", "name", "price"]
}
```

- ✅ Supported.
- Different selected columns produce different keys.
- ⚠️ If the same set is sent with different order (e.g., `name,id,price`), keys may differ.

## B) Basic equality filtering (`attr` / `eq`)

```json
{
  "attr": {
    "status": "active",
    "category_id": 10
  }
}
```

- ✅ Supported.
- Different filter values produce different keys.

## C) Advanced `oper` filtering

```json
{
  "oper": {
    "and": [
      "status|=|active",
      "price|>=|100",
      "created_at|date|2025-01-01"
    ]
  }
}
```

- ✅ Supported.
- Query shape differences produce different keys.
- ⚠️ Semantically equivalent but textually different expressions are treated as different keys.

## D) Relations + nested mode

```json
{
  "relations": ["category:id,name", "tags:id,name"],
  "_nested": true,
  "oper": {
    "category": {
      "and": ["is_public|=|true"]
    }
  }
}
```

- ✅ Supported.
- Relation filters affect response and key fingerprint.
- ⚠️ Reordering relation arrays may reduce hit ratio.

## E) Offset pagination

```json
{
  "pagination": {
    "page": 2,
    "pageSize": 25
  },
  "orderby": [{"id": "desc"}]
}
```

- ✅ Supported.
- Page/pageSize/order changes produce separate keys.

## F) Cursor pagination

```json
{
  "pagination": {
    "infinity": true,
    "pageSize": 20,
    "cursor": "eyJpZCI6MTAwfQ"
  }
}
```

- ✅ Supported.
- Cursor value becomes part of request fingerprint.

## G) Hierarchy mode

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

- ✅ Supported (key differs from non-hierarchy requests).
- Ensure TTL is conservative for large hierarchical trees.

## H) Request-level cache control

```json
{
  "cache": false,
  "cache_ttl": 120
}
```

- ✅ `cache=false` disables cache.
- ✅ `cache_ttl` overrides default TTL when cache is enabled.

---

## 3) Does it handle **all** possible variants?

Short answer: **it handles most practical API variants correctly**, but not all semantic equivalences are normalized.

### Strong points
- Store-agnostic (`redis`, `database`, `file`, etc.)
- Route/user/header-aware keys
- Write invalidation with model version bump
- Supports complex filtering, relations, pagination, hierarchy

### Known limitations (important)
1. No deep canonicalization of arrays and logical trees.
2. Equivalent payloads with different ordering can create different keys.
3. Potentially higher key cardinality for highly dynamic `oper` payloads.

---

## 4) Production readiness checklist

Use this before enabling cache globally:

1. Define vary headers (`tenant`, `locale`, optional guard context).
2. Keep TTL small for complex dynamic queries.
3. Track hit/miss ratio by endpoint.
4. Add canonicalization if key cardinality grows too much.
5. Load test both `redis` and `database` stores for your traffic profile.

---

## 5) Recommended defaults by endpoint type

| Endpoint | Cache | Suggested TTL |
|---|---:|---:|
| `index` static-ish catalog | Yes | 60-120s |
| `getOne` | Yes | 30-60s |
| heavy search (`oper` deep trees) | Conditional | 10-30s |
| exports | Usually no | N/A |
| writes | No | N/A |

---

## 6) Final guidance

If your API frequently receives semantically equivalent JSON in different key/array orders, add a canonicalization layer before hashing the key payload.

If requests are already standardized by clients, current behavior is usually enough for a solid first production rollout.
