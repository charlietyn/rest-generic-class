# 11. Two-Phase Query Strategy

## The Problem: Hibernate + Pagination + Collection JOIN = Disaster

When you write a JPQL `JOIN FETCH` for a collection relationship and combine it with `LIMIT`/`OFFSET` pagination, the database does not behave the way you expect. The root cause is how SQL handles JOINs: each matched row in a joined table produces an additional row in the result set.

### SQL Row Multiplication

Consider a `users` table where each user can have multiple roles:

```sql
-- User with 3 roles: produces 3 rows for 1 user
SELECT u.id, u.name, r.name as role_name
FROM users u
LEFT JOIN user_roles ur ON u.id = ur.user_id
LEFT JOIN roles r ON ur.role_id = r.id
WHERE u.status = 'ACTIVE'
ORDER BY u.name ASC
LIMIT 10;  -- gives 10 ROWS, not 10 USERS!
```

If the first user has 3 roles and the second has 4 roles, `LIMIT 10` returns only the first 3 users (because rows 1–3 belong to user 1, rows 4–7 to user 2, rows 8–10 to part of user 3). The remaining 7 users on "page 1" are simply not returned. The result is silently wrong.

### The Hibernate In-Memory Pagination Fallback

Hibernate detects this situation and, rather than returning wrong data silently, falls back to in-memory pagination. You will see this warning in your logs:

```
HHH90003004: firstResult/maxResults specified with collection fetch; applying in memory!
```

**What this means in practice:**

- Hibernate issues the query **without** `LIMIT`/`OFFSET`
- The full result set (all rows, including all JOIN-multiplied rows) is loaded into the JVM heap
- Hibernate then slices the correct page **in Java**, after the fact

This is correct — but catastrophically expensive. At 10,000 users each with 5 roles, Hibernate loads 50,000 rows into memory and then discards 49,880 of them to return page 1 of 20 results.

### Failing Fast with `fail_on_pagination_over_collection_fetch`

You can configure Hibernate to throw an exception instead of silently doing in-memory pagination:

```yaml
spring:
  jpa:
    properties:
      hibernate:
        query:
          fail_on_pagination_over_collection_fetch: true
```

With this setting enabled, any attempt to use `JOIN FETCH` on a collection with `LIMIT`/`OFFSET` throws an immediate `HibernateException` rather than silently loading all rows. This is recommended in all environments — it forces developers to confront the problem rather than deploy slow, memory-hungry queries to production.

---

## When Does Two-Phase Trigger?

The library automatically detects when two-phase execution is required. The detection logic lives in `TwoPhaseDetector.requiresTwoPhase()`:

```java
// TwoPhaseDetector.requiresTwoPhase() logic:
public boolean requiresTwoPhase(Class<?> rootEntity, List<String> relations, Pagination pagination) {
    if (pagination == null) return false;  // unpaginated → always single-phase
    for (String rel : relations) {
        String[] segments = rel.split("\\.");
        if (pathValidator.isToManyPath(rootEntity, segments)) {
            return true;  // any to-many + pagination → two-phase
        }
    }
    return false;
}
```

The rules are:

1. If there is no pagination, two-phase is never triggered. An unpaginated query can safely use `JOIN FETCH` on collections because there is no `LIMIT`/`OFFSET` to create a wrong boundary.
2. If pagination is present and **any** of the requested relations resolves to a to-many path (i.e., `@OneToMany` or `@ManyToMany`), two-phase is triggered.
3. If all requested relations are to-one (`@ManyToOne`, `@OneToOne`), single-phase is used even with pagination — to-one JOINs do not multiply rows.

### PathValidator.isToManyPath() — Walking the JPA Metamodel

`PathValidator` inspects the JPA static metamodel at runtime to determine whether a given attribute path leads to a plural (collection) association:

```java
// Simplified illustration of what PathValidator does:
EntityType<UserEntity> type = emf.getMetamodel().entity(UserEntity.class);
Attribute<?, ?> attr = type.getAttribute("roles");
boolean isToMany = attr instanceof PluralAttribute;  // true for @OneToMany, @ManyToMany
```

For multi-segment paths like `"department.members"`, `PathValidator` walks each segment in turn: first resolving `department` on `UserEntity` (a `SingularAttribute` — to-one), then resolving `members` on `DepartmentEntity` (a `PluralAttribute` — to-many). If any segment in the path is plural, the path is to-many.

---

## Single-Phase Execution Path

When all relations are to-one, or when there is no pagination, the library uses single-phase execution. This is the simpler path — a standard paginated JPA query with JOINs and a separate `COUNT`.

```java
protected Page<V> searchSinglePhase(QueryPlan plan) {
    Specification<E> spec = buildSpec(plan);
    OrderByCompiler.Result<E> orderResult = orderByCompiler.compile(
        plan.orderItems(), entityClass(), plan.relationIsToMany());

    Pagination pg = plan.pagination();
    long total = count(spec);          // SELECT COUNT(e.id) FROM entity e WHERE ...
    List<E> entities = fetchEntities(spec, orderResult, pg);  // SELECT e FROM entity WHERE ... ORDER BY ... LIMIT/OFFSET
    List<V> views = entities.stream().map(this::toView).toList();
    return new PageImpl<>(views, pg.toPageable(), total);
}
```

Two queries are issued:

1. `COUNT` — to determine total matching records for the `Page` metadata
2. `SELECT` with `ORDER BY` and `LIMIT`/`OFFSET` — to fetch the entities for the current page

Because all JOINs are to-one, there is no row multiplication, and `LIMIT`/`OFFSET` operates on the correct logical units (entities).

---

## Two-Phase Execution Path

When a to-many relation is present alongside pagination, the library splits the work into three queries.

```java
protected Page<V> searchTwoPhase(QueryPlan plan) {
    Specification<E> spec = buildSpec(plan);
    OrderByCompiler.Result<E> orderResult = orderByCompiler.compile(
        plan.orderItems(), entityClass(), plan.relationIsToMany());
    Pagination pg = plan.pagination();

    // Phase 1: count + paginated IDs (SELECT DISTINCT e.id)
    long total = count(spec);
    List<Object> ids = fetchIds(spec, orderResult, pg);

    if (ids.isEmpty()) {
        return new PageImpl<>(List.of(), pg.toPageable(), total);
    }

    // Phase 2: fetch full entities by IDs (no pagination → no cartesian product risk)
    Specification<E> idSpec = (root, q, cb) -> root.get("id").in(ids);
    List<E> entities = fetchEntities(idSpec, orderResult, null);  // null pg = no LIMIT
    List<V> views = entities.stream().map(this::toView).toList();
    return new PageImpl<>(views, pg.toPageable(), total);
}
```

### Phase 1 — fetchIds: The Scalar ID Query

The critical insight of Phase 1 is that it is a **scalar query** — it selects only the `id` column, not entities. Because no entity graph or collection is fetched, Hibernate does not apply `JOIN FETCH` and there is no row multiplication.

```java
// q.select(root.get("id")).distinct(true) → SELECT DISTINCT e.id
// pg.offset() and pg.limit() applied → LIMIT/OFFSET on IDs only
// This is a SCALAR query — no collection JOIN possible → no row multiplication
```

`DISTINCT` is applied at the database level, so even if the `WHERE` clause involves a JOIN to a related table for filtering purposes (e.g., filtering by role name), each entity ID appears only once in the result. The `LIMIT`/`OFFSET` therefore operates on distinct entity IDs — exactly the correct page boundary.

### Phase 2 — fetchEntities: Full Entity Load by IDs

Phase 2 receives the exact set of IDs determined by Phase 1. It fetches the complete entity graph using `WHERE id IN (...)` with **no `LIMIT`/`OFFSET`**:

```java
// WHERE root.get("id").in(ids) → WHERE e.id IN (1,2,3,...)
// null pagination → NO LIMIT/OFFSET
// Now lazy loading or EntityGraph fills collections without pagination interference
```

Because the ID set is already bounded (it contains at most `pageSize` entries), the `IN` clause limits the result to the current page's entities. Collections (roles, images, tags, etc.) are loaded for exactly those entities, with no pagination boundary interfering.

---

## SQL Traces: Actual Queries Generated

The following traces show the three SQL statements issued for a paginated search of users with their roles, filtering by `status = ACTIVE`, ordered by `name ASC`, page 1 of 20:

```sql
-- Phase 1 (count):
SELECT COUNT(e.id) FROM users e
WHERE e.status = 'ACTIVE'

-- Phase 1 (IDs):
SELECT DISTINCT e.id FROM users e
WHERE e.status = 'ACTIVE'
ORDER BY e.name ASC
LIMIT 20 OFFSET 0

-- Phase 2 (entities + collections):
SELECT e.* FROM users e
WHERE e.id IN (1, 5, 12, 18, 23, 31, 44, 52, 67, 78, 83, 91, 105, 112, 120, 134, 145, 156, 178, 190)
ORDER BY e.name ASC
```

The actual SQL emitted by Hibernate will use parameterized placeholders (`?`) rather than inline values, and will include any JOIN clauses required for the filter predicates. The structure above reflects the logical shape of each query.

---

## Performance Comparison

| Strategy | Queries | Memory | Correctness |
|---|---|---|---|
| Naive JOIN FETCH + LIMIT | 1 | HIGH (all rows) | WRONG (in-memory slice) |
| Two-phase (library) | 3 | O(page_size) | CORRECT |
| N+1 without batch | 1 + N | O(page_size) | Correct but slow |
| Batch fetch (default_batch_fetch_size=32) | 1 + N/32 | O(page_size) | Correct |

The two-phase strategy issues 3 queries regardless of collection size. Memory usage is bounded by `pageSize`, not by the total number of matching rows. This makes it the correct default for any paginated endpoint that involves to-many relations.

For comparison, the naive JOIN FETCH approach is both wrong (due to in-memory slicing) and memory-intensive (loads all rows). N+1 is correct but can issue hundreds of queries for large pages. Batch fetch with a reasonable `default_batch_fetch_size` is a viable alternative if you are not using the library's two-phase mechanism.

---

## Edge Cases

### Mixed Relations: To-One and To-Many Together

If the requested relations include both to-one and to-many paths, two-phase triggers because of the to-many:

```
["department", "roles"]
// department = ManyToOne → to-one, safe
// roles      = ManyToMany → to-many, triggers two-phase
```

The to-one relation (`department`) is fetched in Phase 2 via JOIN alongside the entity load. The to-many relation (`roles`) is loaded without a paginated JOIN — either via batch fetch, subselect, or a separate `IN` query, depending on your Hibernate fetch configuration.

### Empty Phase 1 Result

If Phase 1 returns no IDs (no entities match the filter), Phase 2 is skipped entirely:

```java
if (ids.isEmpty()) {
    return new PageImpl<>(List.of(), pg.toPageable(), total);
}
```

This guard is not merely an optimization. Some databases (notably certain MySQL versions and ANSI SQL) treat `WHERE id IN ()` (an empty `IN` list) as a syntax error or return unpredictable results. The guard ensures Phase 2 is only executed when there are valid IDs to query.

### No Pagination — Stays Single-Phase

If the request specifies no pagination (or the service is called programmatically without a `Pagination` object), the two-phase check short-circuits:

```java
if (pagination == null) return false;
```

Without a `LIMIT`/`OFFSET`, an unlimited `JOIN FETCH` on a collection is both correct and often efficient for small-to-medium data sets. The library does not force two-phase on unpaginated queries.

### Deeply Nested To-Many Paths

A path like `"department.employees.projects"` — where `employees` is `@OneToMany` on `DepartmentEntity` — triggers two-phase because the second segment (`employees`) is plural. The full path does not need to terminate at a to-many; any plural segment anywhere in the path is sufficient.

---

## Custom Two-Phase Override

You can override `searchTwoPhase()` in your service subclass to substitute a different Phase 2 implementation — for example, using Blaze-Persistence Entity Views for projection:

```java
@Override
protected Page<V> searchTwoPhase(QueryPlan plan) {
    // Custom: use Spring Data repository with EntityViewSpecificationExecutor for Phase 2
    Page<Object> idsPage = userRepository.findAll(buildSpec(plan), plan.pagination().toPageable());
    List<Long> ids = idsPage.map(obj -> (Long) obj).getContent();
    return userRepository.findAll(
        (root, q, cb) -> root.get("id").in(ids),
        EntityViewSetting.create(UserView.class),
        Sort.by("name")
    );
}
```

This pattern is useful when you want the filtering and ID-selection logic from the library but a more efficient Phase 2 load via Blaze-Persistence projections. See [Chapter 13](13-blaze-persistence-integration.md) for a complete example.

---

## Configuration Reference for Two-Phase Behaviour

```yaml
spring:
  jpa:
    properties:
      hibernate:
        query:
          # Fail fast instead of in-memory pagination (STRONGLY recommended)
          fail_on_pagination_over_collection_fetch: true
        # Batch fetch for Phase 2 collection loading
        default_batch_fetch_size: 32

rest-generic:
  query:
    # Force two-phase even for to-one relations (rarely needed)
    always-two-phase: false
    # Maximum number of IDs allowed in a Phase 2 IN clause
    max-page-size: 200
```

Setting `fail_on_pagination_over_collection_fetch: true` is the single most important configuration for correctness. It catches any case where a collection JOIN + pagination combination slips through the library's detection — for example, when a developer bypasses the base service and writes a custom JPQL query with `JOIN FETCH` and a `Pageable`.
