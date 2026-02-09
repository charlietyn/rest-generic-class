# Generic Cache Architecture for `rest-generic-class` (Laravel 12)

## Audience

Senior Laravel engineers designing **request-aware, backend-agnostic cache layers** for REST APIs with complex query shapes.

---

## 1) Project Query Contract (What defines response shape)

In this package, the response payload is mainly determined by parameters extracted in `RestController::process_request()`:

- `relations`
- `_nested`
- `soft_delete`
- `attr` / `eq` (merged as `attr`)
- `select`
- `pagination`
- `orderby`
- `oper`
- `hierarchy`

These are the minimum fields that must be represented in a cache identity for `index` and `getOne` style reads. If one changes, cache key must change.

**Source anchors**
- Request normalization and merge logic in controller: `src/Core/Controllers/RestController.php`.
- Filter grammar and condition parsing (`field|operator|value`, nested `and/or`, relation filters): `src/Core/Traits/HasDynamicFilter.php`.
- Query execution path (`process_query`, `process_all`, `list_all`, `get_one`): `src/Core/Services/BaseService.php`.

---

## 2) Why cache must be generic by backend

Laravel already provides a unified `Cache` API with interchangeable stores. A robust package design should work with:

- `redis` (preferred for high throughput)
- `database` (portable, infra-light)
- `memcached`
- `file`
- `dynamodb`
- `array` (tests/local only)

Therefore, package cache policy should depend on **Laravel Cache contracts**, not on Redis-only primitives.

---

## 3) Responsibility model: Controller decides policy, cache layer executes

For this project architecture, the cleanest split is:

1. **Controller (or endpoint policy)** decides whether request is cacheable.
2. **Service** focuses on query/business logic.
3. **Cache infrastructure** handles key generation, TTL, and invalidation.

This allows:
- endpoint-level control (`index` cached, `search/live` not cached),
- different TTL profiles by route,
- predictable behavior for advanced clients.

---

## 4) Cache key design for complex query schemas

### 4.1 Required key dimensions

At minimum, include:

- resource identity (`model`)
- operation (`list_all`, `get_one`, `show`, etc.)
- route identity (`route_name` or normalized path)
- HTTP method
- normalized query schema (`select`, `relations`, `oper`, `attr`, `pagination`, `orderby`, `hierarchy`, etc.)
- auth scope (`user_id` or role scope if response depends on permissions)
- tenant scope (`tenant_id` or equivalent header/claim)
- locale scope (`Accept-Language` if localized responses)
- cache schema version (to force global key evolution)
- model data version (for invalidation without tags)

### 4.2 Canonicalization rules (critical)

Complex queries can be semantically equal but textually different. To avoid key explosion and low hit ratio, canonicalize:

1. **Associative arrays**: sort keys recursively.
2. **Boolean/string normalization**: normalize `"true"/"false"` to bool where applicable.
3. **Numeric normalization**: cast numeric strings where safe.
4. **`relations`**:
   - if order is non-semantic in your API contract, sort relation list.
   - preserve nested field projections deterministically (`relation:id,name` normalized order).
5. **`select`**:
   - if set semantics are intended, sort columns.
   - if order is meaningful downstream, preserve order.
6. **`oper` trees**:
   - preserve sequence for explicit boolean logic if order matters.
   - normalize whitespace/casing in operators.
7. **`pagination`**:
   - include cursor/page/pageSize explicitly.
8. **`orderby`**:
   - preserve order (SQL order clauses are positional).

### 4.3 Stable serialization

After canonicalization, serialize using deterministic JSON and hash (`sha1`/`sha256`) to keep keys short:

```text
rgc:v2:{model}:{operation}:{hash(canonical_payload)}
```

---

## 5) Invalidation strategy (store-agnostic)

Because not all stores support tags efficiently, use **versioned keys**:

- Maintain a per-model version key (e.g., `rgc:v2:version:{ModelClass}`)
- Include version in every read key payload
- On successful writes (`create`, `update`, `destroy`, bulk ops), increment model version

Benefits:
- Works on Redis, database, file, memcached
- No mass delete scan
- Predictable stale cutoff

Tradeoff:
- Old keys expire by TTL (lazy cleanup)

---

## 6) Backend notes and production recommendations

## Redis (recommended)
- Best latency and throughput.
- Use `phpredis` in production.
- Add memory policy + keyspace sizing alerts.

## Database cache store
- Good fallback when Redis is unavailable.
- Ensure proper indexing and table maintenance.
- Be careful with very short TTL + high QPS.

## File store
- Acceptable for dev/small workloads.
- Not ideal for multi-node horizontal scaling.

## Memcached
- Fast but volatile; design for evictions.

---

## 7) Security and multitenancy safeguards

Never share cache entries across security boundaries. Include in key scope any field that changes authorization or visible dataset:

- tenant identifier
- authenticated user identity (if row-level policies apply)
- locale
- permission profile/guard when relevant

If your app uses Spatie permissions and dynamic guards, ensure the key scope includes guard context when responses differ.

---

## 8) Operational policy matrix (recommended)

| Endpoint pattern | Cache | TTL | Notes |
|---|---:|---:|---|
| Catalog lists (`index`) | Yes | 30-120s | High hit ratio |
| Single read (`show/getOne`) | Yes | 15-60s | Include tenant/user scope |
| Search with highly dynamic filters | Conditional | 5-30s | Evaluate hit ratio first |
| Writes (`store/update/destroy`) | No | N/A | Bump model version |
| Export endpoints | Usually no | N/A | Heavy payload, often user-specific |

---

## 9) Rollout checklist for this package

1. Define endpoint cache policy (controller-level or middleware profile).
2. Keep backend generic via Laravel `Cache` store config.
3. Implement strict query canonicalization for package schema.
4. Add per-model version invalidation.
5. Add observability:
   - hit/miss counters
   - key cardinality by route
   - P95 latency per store
6. Validate with real complex `oper` + `relations` payloads.
7. Load test Redis and database store profiles before production cutover.

---

## 10) Suggested `.env` baseline

```env
REST_CACHE_ENABLED=true
REST_CACHE_STORE=redis
REST_CACHE_TTL=60
REST_CACHE_TTL_LIST=60
REST_CACHE_TTL_ONE=30
```

Switching to database is only a config change:

```env
REST_CACHE_STORE=database
```

This is the core of a **generic cache strategy**: same package behavior, different backend driver.

---

## Final recommendation

For long-term maintainability in this library:

- Keep query execution concerns in services.
- Keep cache policy decision near endpoint orchestration.
- Keep key schema canonical, explicit, and versioned.
- Keep backend choice pluggable via Laravel cache stores.

That combination gives predictable correctness on complex queries and portability across cache databases.
