# Chapter 6: Dynamic Ordering

Dynamic ordering lets API consumers control the sort order of query results at runtime without writing custom repository methods. The library compiles a declarative `orderby` array into a combination of Spring Data `Sort` objects and JPA `Specification` correlated subqueries, handling both simple field sorts and the notoriously difficult to-many relation ordering problem.

---

## The `orderby` Format

The `orderby` key in a POST /search request body is an **array of single-key objects**. Each object maps exactly one field path to a direction string.

```json
"orderby": [
  {"name": "asc"},
  {"createdAt": "desc"},
  {"department.name": "asc"},
  {"roles.createdAt": "desc"}
]
```

**Rules:**

- The array is processed in declaration order — earlier items have higher sort priority.
- Each object must contain exactly one key-value pair.
- Direction values are `"asc"` or `"desc"` (case-insensitive; `"ASC"` and `"DESC"` are also accepted).
- Every field path must appear in the entity's `@AllowedOrderBy` annotation or the request is rejected with HTTP 400.
- Dot notation supports multi-level paths: `"manager.department.name"`.

**Minimal example — single field:**

```json
{
  "pagination": {"page": 1, "pageSize": 20},
  "orderby": [{"name": "asc"}]
}
```

---

## OrderByCompiler and the `Result<E>` Record

The `OrderByCompiler.compile()` method processes the list of `OrderByItem` values and returns a typed `Result<E>` record:

```java
public record Result<E>(Sort sort, Specification<E> subqueryOrderSpec) {
    public boolean hasSubqueryOrdering() {
        return subqueryOrderSpec != null;
    }
}
```

- `sort` — a Spring Data `Sort` built from all local fields and to-one relation paths. May be `Sort.unsorted()` if every item goes to the subquery path.
- `subqueryOrderSpec` — a JPA `Specification<E>` that injects scalar correlated subqueries into the `CriteriaQuery.orderBy()` list. `null` when no to-many paths are present.
- `hasSubqueryOrdering()` — convenience predicate used by `BaseQueryService` to decide whether a `Specification` must be applied.

---

## Two Paths Through OrderByCompiler

Every `OrderByItem` is classified into one of three routing categories:

### Path 1 — Local Field

A path with no dots refers to a field on the root entity itself (e.g., `name`, `createdAt`, `status`).

```java
// Input: {"name": "asc"}
Sort.by(Sort.Order.asc("name"))

// Input: {"createdAt": "desc"}
Sort.by(Sort.Order.desc("createdAt"))
```

Spring Data translates these directly into SQL `ORDER BY` clauses with no JOIN required.

### Path 2 — To-One Relation Path

A dotted path whose every segment resolves to a `@ManyToOne` or `@OneToOne` association (e.g., `department.name`).

```java
// Input: {"department.name": "asc"}
Sort.by("department.name")
```

Spring Data JPA handles this by generating a `LEFT OUTER JOIN` to the related table. Because to-one joins produce at most one row per root entity, there is no duplicate-row problem.

### Path 3 — To-Many Relation Path

A path whose first segment resolves to a `@OneToMany` or `@ManyToMany` association (e.g., `roles.createdAt`). This cannot safely go through `Sort` — see the next section for the full explanation.

```java
// Input: {"roles.createdAt": "desc"}
// → Specification<E> containing a scalar correlated subquery
```

---

## The To-Many Ordering Problem

Naively joining a to-many relation for ordering causes duplicate rows in the result set, which breaks pagination.

### What Goes Wrong with a JOIN

```sql
-- WRONG: JOIN with ManyToMany causes duplicate rows
SELECT u.*
FROM users u
LEFT JOIN user_roles ur ON u.id = ur.user_id
LEFT JOIN roles r       ON ur.role_id = r.id
ORDER BY r.name ASC
LIMIT 10;
-- Gives 10 ROWS, not 10 USERS — a user with 3 roles appears 3 times
```

With `LIMIT 10` applied after the join, you may receive 4 users instead of 10 because several users each appear multiple times.

### The Scalar Subquery Solution

`OrderByCompiler` avoids this entirely by replacing the JOIN with a correlated scalar subquery in the `ORDER BY` clause:

```sql
-- CORRECT: scalar subquery per row, no duplicates
SELECT u.*
FROM users u
ORDER BY (
  SELECT r.name
  FROM roles r
  JOIN user_roles ur ON r.id = ur.role_id
  WHERE ur.user_id = u.id
  LIMIT 1
) ASC
LIMIT 10;
-- Gives exactly 10 USERS, one subquery evaluation per user
```

The subquery selects a single representative value from the related table for each root row. The outer query sees one row per user regardless of how many roles they have.

---

## How `buildSubqueryOrderSpec` Works

When `OrderByCompiler` detects a to-many path, it builds a `Specification<E>` using the JPA Criteria API:

1. **Split the path** — e.g., `"roles.createdAt"` → `relPath = ["roles"]`, `finalField = "createdAt"`. A multi-segment path like `"department.roles.createdAt"` → `relPath = ["department", "roles"]`, `finalField = "createdAt"`.

2. **Create a correlated subquery** — `sub.correlate(root)` binds the subquery to the outer query's root entity so the `WHERE` clause can reference the outer row's primary key.

3. **Walk `relPath` segments** — each segment is joined with `JoinType.LEFT` on the subquery's correlated root.

4. **Select `finalField`** — the subquery's `select` expression targets the attribute on the final join alias.

5. **Inject into `orderBy`** — the returned `Specification<E>` calls `query.orderBy(cb.asc(subquery))` or `query.orderBy(cb.desc(subquery))` when applied.

The `Specification` is passed to `BaseQueryService`, which calls `repository.findAll(combinedSpec, sort)`. The combined spec merges the filter spec, the subquery order spec, and the `Sort` from the `Result<E>`.

---

## `@AllowedOrderBy` Enforcement

Every order-by path is validated against an allowlist declared on the entity class.

```java
@AllowedOrderBy({"name", "email", "createdAt", "department.name", "roles.createdAt"})
@Entity
public class UserEntity { ... }
```

**Enforcement flow:**

1. `OrderByCompiler.validate()` is called for each `OrderByItem` before compilation.
2. `AllowlistRegistry.isOrderByAllowed(entityClass, path)` checks the annotation value.
3. If the path is **not** in the annotation, an `InvalidRelationException` is thrown.
4. The exception propagates to the controller's exception handler and becomes an **HTTP 400 Bad Request** with a descriptive error body.

**Example — rejected request:**

```json
// @AllowedOrderBy({"name", "department.name"}) — "email" NOT listed
{
  "orderby": [{"email": "asc"}]
}
// Response: 400 Bad Request
// { "error": "Order-by field 'email' is not allowed for UserEntity" }
```

The allowlist exists to prevent information-disclosure attacks (ordering by secret fields), unintended cross-join performance problems, and to give developers explicit control over which sort dimensions are supported.

---

## Multi-Level Dot Notation

A path can traverse more than one relation level:

```json
{"manager.department.name": "asc"}
```

`OrderByCompiler` splits on the first dot boundary:

- `relPath = ["manager", "department"]`
- `finalField = "name"`

If `manager` is to-one and `department` is also to-one, the entire path goes through `Sort` as a multi-hop Spring Data join path. If any segment in `relPath` is to-many, the whole path uses the correlated subquery strategy.

Each segment in `relPath` must be individually listed (via the full dotted path) in `@AllowedOrderBy`. For example, `@AllowedOrderBy({"manager.department.name"})` enables `"manager.department.name"` but not `"manager.name"` — each path must be declared explicitly.

---

## Combining Multiple `orderby` Items

Multiple items result in a compound sort. Items are processed left to right (highest priority first).

```json
{
  "pagination": {"page": 1, "pageSize": 25},
  "orderby": [
    {"department.name": "asc"},
    {"name": "asc"},
    {"createdAt": "desc"}
  ]
}
```

Processing:

| Item | Path type | Destination |
|------|-----------|-------------|
| `department.name` | to-one | Added to `Sort` as `"department.name ASC"` |
| `name` | local field | Added to `Sort` as `"name ASC"` |
| `createdAt` | local field | Added to `Sort` as `"createdAt DESC"` |

`BaseQueryService` merges the `Sort` orders from `Result<E>` and applies any subquery spec. If `department` were a to-many relation, its item would instead produce a `Specification<E>` and be injected as a scalar subquery — the remaining `Sort` items are still appended as secondary sorts in the `ORDER BY` clause.

---

## OrderBy and the Two-Phase Strategy

When to-many relations trigger two-phase query execution, ordering is applied consistently across both phases:

- **Phase 1** (SELECT DISTINCT ids, paginated): The `Sort` and subquery ordering spec from `Result<E>` are applied to produce a correctly ordered, correctly paginated ID list.
- **Phase 2** (SELECT WHERE id IN (ids)): The same `OrderByCompiler.Result<E>` is reused. The scalar subquery is rebuilt and applied to the entity-fetch query so that the returned list respects the original sort order.

This means the final `Page<V>` content is always in the requested order, even when to-many joins would otherwise interfere with `LIMIT`.

---

## Ordering Examples

### Example 1 — Simple ascending sort

```json
POST /users/search
{
  "pagination": {"page": 1, "pageSize": 20},
  "orderby": [{"name": "asc"}]
}
```

Produces: `ORDER BY u.name ASC LIMIT 20`

---

### Example 2 — Descending by timestamp

```json
POST /users/search
{
  "pagination": {"page": 1, "pageSize": 10},
  "orderby": [{"createdAt": "desc"}]
}
```

Produces: `ORDER BY u.created_at DESC LIMIT 10`

---

### Example 3 — To-one relation sort

```json
POST /employees/search
{
  "pagination": {"page": 1, "pageSize": 50},
  "orderby": [{"department.name": "asc"}]
}
```

Spring Data generates `LEFT JOIN department d ON e.department_id = d.id ORDER BY d.name ASC`

---

### Example 4 — To-many relation sort (scalar subquery)

```json
POST /users/search
{
  "pagination": {"page": 1, "pageSize": 20},
  "orderby": [{"roles.createdAt": "desc"}]
}
```

Produces:

```sql
SELECT u.* FROM users u
ORDER BY (
  SELECT r.created_at FROM roles r
  JOIN user_roles ur ON r.id = ur.role_id
  WHERE ur.user_id = u.id LIMIT 1
) DESC
LIMIT 20
```

---

### Example 5 — Multi-field compound sort

```json
POST /products/search
{
  "pagination": {"page": 1, "pageSize": 30},
  "orderby": [
    {"category.name": "asc"},
    {"price": "asc"},
    {"name": "asc"}
  ]
}
```

`category.name` → to-one → `Sort`; `price` and `name` → local → `Sort`. Final: `ORDER BY category.name ASC, price ASC, name ASC`

---

### Example 6 — Multi-level nested path

```json
POST /employees/search
{
  "pagination": {"page": 1, "pageSize": 15},
  "orderby": [{"manager.department.name": "asc"}]
}
```

If both `manager` and `department` are to-one, Spring Data walks: `LEFT JOIN managers m ON e.manager_id = m.id LEFT JOIN departments d ON m.department_id = d.id ORDER BY d.name ASC`

---

### Example 7 — Mixed to-one and to-many on the same request

```json
POST /users/search
{
  "pagination": {"page": 1, "pageSize": 20},
  "orderby": [
    {"department.name": "asc"},
    {"roles.createdAt": "desc"},
    {"name": "asc"}
  ]
}
```

`department.name` → `Sort` (to-one); `roles.createdAt` → scalar subquery `Specification`; `name` → `Sort`. Two-phase strategy is triggered because `roles` is to-many and pagination is present.

---

### Example 8 — Ordering without filtering

```json
POST /products/search
{
  "pagination": {"page": 2, "pageSize": 10},
  "orderby": [{"price": "desc"}, {"name": "asc"}]
}
```

No `oper` key → no `WHERE` clause. Produces: `SELECT * FROM products ORDER BY price DESC, name ASC LIMIT 10 OFFSET 10`
