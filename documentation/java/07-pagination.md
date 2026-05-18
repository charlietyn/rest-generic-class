# Chapter 7: Pagination

The library provides first-class pagination support through a strongly typed `Pagination` record that translates a 1-based page/pageSize API into Spring Data's 0-based `Pageable` abstraction. This chapter covers the request format, the underlying math, performance characteristics, and the relationship between pagination and two-phase query execution.

---

## The `pagination` Object

Include a `pagination` key in any POST /search request body to receive paginated results:

```json
{
  "pagination": {"page": 1, "pageSize": 20},
  "oper": {"and": ["status|=|ACTIVE"]}
}
```

| Field | Type | Minimum | Maximum | Default |
|-------|------|---------|---------|---------|
| `page` | integer | 1 | unbounded | 1 |
| `pageSize` | integer | 1 | 1000 | 20 |

- `page` is **1-based**: page 1 is the first page of results.
- Values below the minimum are clamped up (page 0 becomes page 1).
- `pageSize` values above 1000 are clamped down to 1000.

**Default pagination** — when no `pagination` key is provided, `Pagination.DEFAULT` is used for GET / requests, and `null` is used for POST /search (meaning no `LIMIT`/`OFFSET` is applied). See the section on null pagination below.

---

## The `Pagination` Record

```java
public record Pagination(int page, int pageSize) {

    public static final Pagination DEFAULT = new Pagination(1, 20);

    public int offset() {
        return (page - 1) * pageSize;
    }

    public int limit() {
        return pageSize;
    }

    public Pageable toPageable() {
        return PageRequest.of(page - 1, pageSize);
    }

    public static Pagination of(int page, int pageSize) {
        int clampedPage     = Math.max(1, page);
        int clampedPageSize = Math.min(1000, Math.max(1, pageSize));
        return new Pagination(clampedPage, clampedPageSize);
    }
}
```

Key points:

- `offset()` converts from 1-based to 0-based for raw SQL or JPQL `setFirstResult`.
- `toPageable()` converts from 1-based to 0-based for Spring Data `PageRequest` (which uses 0-indexed page numbers internally).
- `of()` is the safe factory method used by request deserialization — it clamps both values before construction.

---

## Query-Param Style (GET /)

The `GET /` endpoint reads pagination from URL query parameters:

```
GET /users?page=2&pageSize=50&oper={"and":["status|=|ACTIVE"]}
```

- Parameters are optional. Omitted `page` defaults to `1`; omitted `pageSize` defaults to `20`.
- The `oper` parameter accepts a URL-encoded JSON filter expression.
- The same `Pagination` record is constructed from these params and passed to the same query pipeline as POST /search.

---

## Spring Data `Page<V>` Response

All paginated endpoints return Spring Data's standard `Page<V>` serialized to JSON:

```json
{
  "content": [
    {"id": 1, "name": "Alice", "status": "ACTIVE"},
    {"id": 2, "name": "Bob",   "status": "ACTIVE"}
  ],
  "pageable": {
    "pageNumber": 1,
    "pageSize":   50,
    "offset":     50,
    "sort":       {"sorted": true, "unsorted": false, "empty": false},
    "paged":      true,
    "unpaged":    false
  },
  "totalElements":    247,
  "totalPages":       5,
  "last":             false,
  "first":            false,
  "size":             50,
  "number":           1,
  "numberOfElements": 50,
  "empty":            false
}
```

**Notable fields:**

| Field | Meaning |
|-------|---------|
| `content` | The actual page of view objects |
| `totalElements` | Total matching rows across all pages |
| `totalPages` | `ceil(totalElements / pageSize)` |
| `number` | 0-based page number (Spring Data convention) |
| `first` / `last` | Whether this is the first or last page |
| `numberOfElements` | Items actually returned (may be less than `pageSize` on the last page) |

Note that `pageable.pageNumber` is 0-based even though the request uses 1-based `page`. This is a Spring Data convention; clients should use `first`/`last` flags for navigation rather than incrementing `pageNumber`.

---

## Offset Pagination Math

The mapping from 1-based request parameters to SQL `LIMIT`/`OFFSET`:

| Request `page` | Request `pageSize` | SQL `OFFSET` | SQL `LIMIT` |
|---------------|-------------------|-------------|------------|
| 1 | 20 | 0 | 20 |
| 2 | 20 | 20 | 20 |
| 3 | 20 | 40 | 20 |
| 1 | 50 | 0 | 50 |
| 2 | 50 | 50 | 50 |
| N | S  | (N-1) × S | S |

Formula: `offset = (page - 1) * pageSize`

**Example walkthrough — page 3, pageSize 20:**

```
offset = (3 - 1) * 20 = 40
SELECT * FROM users ORDER BY id ASC LIMIT 20 OFFSET 40
```

This skips the first 40 rows and returns rows 41–60.

---

## Performance Characteristics of Offset Pagination

Offset pagination is simple and universally supported but has important performance implications for large tables.

### The Offset Scan Problem

```sql
-- page=500, pageSize=20 → OFFSET=9980
SELECT * FROM users ORDER BY created_at DESC LIMIT 20 OFFSET 9980;
```

Most relational databases must **scan and discard** the first 9,980 rows before returning the 20 you want. This means:

- Page 1 is O(1) with an index.
- Page 500 is O(9,980) — the database reads 9,980 rows it throws away.
- Deep pagination on large tables causes latency that grows linearly with page number.

### Mitigation Strategies

| Strategy | Effectiveness | Effort |
|----------|---------------|--------|
| Keep `pageSize` small (≤ 50) | Moderate | None |
| Add covering indexes on `ORDER BY` columns | High | Low |
| Use `pageSize` ≤ 100 and cap `page` at a reasonable maximum | High | Low |
| Switch to keyset/cursor pagination for deep traversal | Highest | High (future roadmap) |

**Covering index example** — if you frequently order by `createdAt DESC` and filter by `status`:

```sql
CREATE INDEX idx_users_status_created ON users (status, created_at DESC);
```

With this index, the database can satisfy both the `WHERE status = 'ACTIVE'` predicate and the `ORDER BY created_at DESC` sort using a single index scan, skipping the offset efficiently.

### When Offset Is Fine

Offset pagination is perfectly adequate when:

- Total result sets are small (< 10,000 rows after filtering).
- Users rarely navigate beyond the first 10–20 pages.
- The ORDER BY column is indexed.

---

## The Counting Strategy

Every paginated query runs a **separate COUNT query** to populate `totalElements`:

```sql
-- Data query
SELECT e.* FROM users e WHERE e.status = 'ACTIVE' ORDER BY e.name ASC LIMIT 20 OFFSET 0;

-- Count query (same predicate, no ORDER BY, no LIMIT)
SELECT COUNT(e.id) FROM users e WHERE e.status = 'ACTIVE';
```

The count query shares the same `Specification<E>` (WHERE predicate) as the data query but has no `ORDER BY`, `LIMIT`, or `OFFSET`. This is standard Spring Data behavior — `JpaRepository.findAll(Specification, Pageable)` fires both queries automatically.

**In two-phase execution**, the count runs before Phase 1:

1. COUNT query → `totalElements`
2. Phase 1: SELECT DISTINCT ids WHERE spec ORDER BY ... LIMIT/OFFSET → id list
3. Phase 2: SELECT WHERE id IN (id list) → entities

The `Page<V>` is assembled with the count from step 1 and the entities from step 3.

---

## Null Pagination — Unpaginated Queries

If the `pagination` key is absent from a POST /search request body, the library receives a `null` `Pagination` value and applies **no `LIMIT` or `OFFSET`**:

```json
{
  "oper": {"and": ["status|=|ACTIVE"]}
}
```

This returns all matching rows as a single page with `totalElements = numberOfElements`.

**Behaviors with null pagination:**

- `TwoPhaseDetector` returns `false` — two-phase only triggers when pagination is present, because the purpose of two-phase is to avoid in-memory pagination with collection JOINs.
- The response is still a `Page<V>` object, but `totalPages = 1` and `last = true`.
- Ordering (`orderby`) still works normally.
- Use with caution on large tables — without a `LIMIT`, a query may return millions of rows.

**Appropriate use cases for null pagination:**

- Export operations (CSV/Excel generation) where all rows are needed.
- Small reference data tables (countries, currencies, categories) that are always fetched in full.
- Administrative batch operations triggered from a back-office UI.

---

## Why `fail-on-pagination-over-collection-fetch=true` Is Essential

This Hibernate property must be set in `application.properties`:

```properties
spring.jpa.properties.hibernate.query.fail_on_pagination_over_collection_fetch=true
```

### The Problem It Guards Against

When Hibernate encounters a JPQL query that uses `JOIN FETCH` on a collection (`@OneToMany`, `@ManyToMany`) combined with `LIMIT`/`OFFSET`, it cannot apply pagination in SQL because the JOIN produces multiple rows per root entity. It falls back to **in-memory pagination**:

```
WARN  o.h.h.internal.ast.QueryTranslatorImpl -
HHH90003004: firstResult/maxResults specified with collection fetch;
applying in memory!
```

This means Hibernate:

1. Executes the query **with no LIMIT** — fetches the entire result set.
2. Loads all rows into memory.
3. Slices the in-memory list to the requested page.

On a 1,000,000-row table, requesting page 1 with pageSize 20 causes all 1,000,000 rows to be loaded into the JVM heap.

### With the Property Set to `true`

```properties
hibernate.query.fail_on_pagination_over_collection_fetch=true
```

Instead of silent in-memory pagination, Hibernate throws a `HibernateException` immediately:

```
org.hibernate.HibernateException: firstResult/maxResults specified
with collection fetch. In memory pagination was about to be applied.
Failing because 'hibernate.query.fail_on_pagination_over_collection_fetch' is enabled.
```

This turns a silent, catastrophic performance problem into a loud, immediately diagnosable error.

### How the Two-Phase Strategy Avoids This Entirely

The library's two-phase execution never constructs a query that has both a collection JOIN and a `LIMIT`:

- **Phase 1** — selects only IDs with `SELECT DISTINCT id`. No `JOIN FETCH` on collections. `LIMIT`/`OFFSET` are safe here.
- **Phase 2** — fetches full entities with `WHERE id IN (...)`. Uses `JOIN FETCH` or EntityGraph for collection loading. No `LIMIT` — the ID list already bounds the result set.

The property acts as a safety net, catching any case where the two-phase detector misclassifies a query or a developer writes a custom query that accidentally combines both.

---

## Pagination Without Filtering

A `pagination` object can be sent with no `oper` key:

```json
{
  "pagination": {"page": 1, "pageSize": 20}
}
```

- No `oper` → `FilterNode` is `null` → no `WHERE` clause is generated.
- SQL: `SELECT * FROM table ORDER BY <default sort> LIMIT 20 OFFSET 0`
- Response includes `totalElements` = total row count of the table.
- Ordering (`orderby`) still applies if provided.

This is useful for browsing all records with predictable pagination, for example in admin list views.

---

## Pagination Examples

### Example 1 — First page, default size

```json
POST /users/search
{
  "pagination": {"page": 1, "pageSize": 20}
}
```

SQL: `SELECT * FROM users LIMIT 20 OFFSET 0`

---

### Example 2 — Third page with filter

```json
POST /users/search
{
  "pagination": {"page": 3, "pageSize": 25},
  "oper": {"and": ["status|=|ACTIVE"]}
}
```

SQL: `SELECT * FROM users WHERE status = 'ACTIVE' LIMIT 25 OFFSET 50`

---

### Example 3 — GET / with query params

```
GET /products?page=2&pageSize=10&oper={"and":["price|>|50"]}
```

SQL: `SELECT * FROM products WHERE price > 50 LIMIT 10 OFFSET 10`

---

### Example 4 — Large page size (clamped)

```json
POST /products/search
{
  "pagination": {"page": 1, "pageSize": 5000}
}
```

`pageSize` 5000 is clamped to 1000. SQL: `SELECT * FROM products LIMIT 1000 OFFSET 0`

---

### Example 5 — Null pagination (export all)

```json
POST /countries/search
{
  "oper": {"and": ["active|=|true"]}
}
```

No `pagination` key → no `LIMIT`. SQL: `SELECT * FROM countries WHERE active = true`

---

### Example 6 — Pagination with ordering

```json
POST /orders/search
{
  "pagination": {"page": 1, "pageSize": 50},
  "orderby": [{"createdAt": "desc"}, {"id": "asc"}],
  "oper": {"and": ["status|=|PENDING"]}
}
```

SQL: `SELECT * FROM orders WHERE status = 'PENDING' ORDER BY created_at DESC, id ASC LIMIT 50 OFFSET 0`
