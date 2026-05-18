# Chapter 8: Relation Loading

The library provides three mechanisms for loading associated entities: lazy loading via Hibernate proxies, explicit EntityGraph construction in `findByIdWithRelations()`, and two-phase query execution for search endpoints. This chapter explains each mechanism, how the `relations` array in a request body controls eager loading, and how to avoid N+1 queries.

---

## The `relations` Array

The `relations` array in a POST /search or GET /{id}/with request body specifies which associations should be eagerly loaded for the returned entities:

```json
"relations": ["department", "manager", "manager.department", "roles"]
```

**Rules:**

- Each string is a dot-notation path to an association attribute on the entity.
- `"manager.department"` means: load `manager`, and for each loaded `manager`, also load its `department`.
- Every path must be listed in the entity's `@AllowedRelations` annotation or the request is rejected with HTTP 400.
- Segment count is not strictly limited, but deeply nested paths (three or more levels) carry increasing query cost.

---

## How `findByIdWithRelations()` Builds an EntityGraph

The `GET /{id}/with` endpoint calls `findByIdWithRelations(id, relations)`, which constructs a JPA EntityGraph at runtime from the requested relation paths:

```java
public Optional<V> findByIdWithRelations(Object id, List<String> relations) {
    var graph = em.createEntityGraph(entityClass());
    for (String rel : relations) {
        String[] segments = rel.split("\\.", 2);
        graph.addAttributeNodes(segments[0]);
        if (segments.length > 1) {
            graph.addSubgraph(segments[0]).addAttributeNodes(segments[1]);
        }
    }
    Map<String, Object> hints = Map.of("jakarta.persistence.fetchgraph", graph);
    E entity = em.find(entityClass(), id, hints);
    return Optional.ofNullable(entity).map(this::toView);
}
```

**Step-by-step:**

1. `em.createEntityGraph(entityClass())` — creates an empty dynamic graph for the root entity type.
2. For each path, split on the first dot: `"manager.department"` → `segments = ["manager", "department"]`.
3. `graph.addAttributeNodes(segments[0])` — adds `manager` as an eagerly loaded attribute node.
4. If a second segment exists, `graph.addSubgraph("manager").addAttributeNodes("department")` — adds a subgraph under `manager` that eagerly loads `department`.
5. The graph is passed as a `fetchgraph` hint to `em.find()`.

### `fetchgraph` vs `loadgraph`

| Hint | Behavior |
|------|---------|
| `jakarta.persistence.fetchgraph` | Loads **only** the attributes in the graph; all others are treated as LAZY regardless of their mapping |
| `jakarta.persistence.loadgraph` | Loads graph attributes **plus** any attributes mapped as `FetchType.EAGER` by default |

The library uses `fetchgraph` to guarantee that only explicitly requested associations are loaded in a single `SELECT`. This prevents accidental eager loading of heavyweight collections.

---

## Allowlist Enforcement

Every relation path is validated against an allowlist declared on the entity class:

```java
@AllowedRelations({"department", "manager", "manager.department", "roles", "roles.permissions"})
@Entity
public class UserEntity { ... }
```

**Enforcement flow:**

1. `PathValidator.validateRelationPath()` is called for each path in the `relations` array.
2. `AllowlistRegistry.isRelationAllowed(entityClass, path)` checks the `@AllowedRelations` annotation.
3. An unlisted path throws `InvalidRelationException`.
4. The exception becomes **HTTP 400 Bad Request** with a descriptive message.

**Example — rejected request:**

```json
// @AllowedRelations({"department"}) — "roles" NOT listed
{
  "relations": ["roles"]
}
// Response: 400 Bad Request
// { "error": "Relation 'roles' is not allowed for UserEntity" }
```

The allowlist prevents clients from triggering unbounded lazy loading chains and controls exactly which associations are safe to expose via the API.

---

## Relation Loading in Different Contexts

Relation loading behaves differently depending on which endpoint or service method is involved:

### 1. `search()` — POST /search and GET /

Relations stored in `QueryPlan.requestedRelations` are **not** used for JOIN loading in the base `BaseQueryService` implementation. Their primary role in `search()` is to inform `TwoPhaseDetector`: if any requested relation is to-many and pagination is active, two-phase execution is triggered.

The `toView(E entity)` method is responsible for mapping loaded data. If you want `search()` to eagerly load relations, override `searchSinglePhase()` or `searchTwoPhase()` to construct a custom query with the required joins or EntityGraph, then call `toView()`.

### 2. `findById(id)` — GET /{id}

No relation loading. The entity is fetched with `em.find(entityClass(), id)` using default fetch settings. Collections remain LAZY proxies; accessing them outside a transaction causes a `LazyInitializationException`.

### 3. `findByIdWithRelations(id, relations)` — GET /{id}/with

Full EntityGraph loading as described above. The entity and all specified associations are loaded in a single SQL SELECT (using JOIN or secondary SELECT depending on the association type and Hibernate's batch strategy).

---

## Effect of Relations on Two-Phase Detection

The `TwoPhaseDetector` inspects the requested relations combined with the presence of pagination to decide whether to activate two-phase execution:

| Relations requested | Pagination present | Result |
|--------------------|--------------------|--------|
| `["department"]` (to-one) | yes | Single-phase — no duplicate rows possible |
| `["roles"]` (to-many) | yes | **Two-phase** — roles is ManyToMany |
| `["department", "roles"]` | yes | **Two-phase** — roles is ManyToMany |
| `["department", "roles"]` | no | Single-phase — no LIMIT, so in-memory is acceptable |
| none | yes | Single-phase |

**Detection logic:**

1. For each path in `requestedRelations`, resolve the association type via the JPA metamodel.
2. If any resolved type is `@OneToMany` or `@ManyToMany`, set `requiresTwoPhase = true`.
3. `requiresTwoPhase` is only acted upon if `pagination != null`.

---

## N+1 Prevention

Lazy loading is a frequent source of N+1 query patterns: one query to fetch 20 entities, then one query per entity to load an association = 21 queries instead of 1.

### Strategy 1 — Hibernate Batch Fetching (default)

Configure `hibernate.default_batch_fetch_size` in `application.properties`:

```properties
spring.jpa.properties.hibernate.default_batch_fetch_size=32
```

When Hibernate lazy-loads an association for one entity and detects that others in the same session also need the same association, it issues a single `WHERE id IN (...)` query for up to 32 IDs at once. This reduces N+1 to ceil(N/32)+1 queries.

**Appropriate for:** any lazy collection access inside `toView()` when the result list is small.

### Strategy 2 — Explicit EntityGraph in `findByIdWithRelations()`

Use `GET /{id}/with?relations=roles,department` for single-entity detail views. The EntityGraph loads all specified associations in one query, producing zero N+1 queries.

**Appropriate for:** detail pages that display nested data from one entity.

### Strategy 3 — Custom `searchSinglePhase()` Override

Override the search phase to build a JPQL query with explicit `JOIN FETCH` for to-one associations:

```java
@Override
protected Page<V> searchSinglePhase(QueryPlan plan) {
    // Custom JPQL: always LEFT JOIN FETCH department (to-one, safe with pagination)
    // Build TypedQuery, apply pagination, return Page<V>
    // ... custom implementation ...
}
```

**Appropriate for:** search endpoints that always need specific to-one associations and where N+1 is measurably impacting performance.

### Strategy 4 — Blaze-Persistence Entity Views (see Chapter 13)

Entity Views allow Hibernate to project into a DTO type using an optimized query, loading only the columns you declare. Zero N+1, minimal data transfer. Highest-effort, highest-performance option.

---

## Entity Graph vs JOIN FETCH — Decision Table

| Strategy | Use case | Risk |
|----------|----------|------|
| EntityGraph (`fetchgraph`) | `findById` + explicit relations | None — no LIMIT interference |
| JOIN FETCH (to-one only) | `search()` with to-one associations | Safe for to-one; never use with to-many + pagination |
| JOIN FETCH + collection | Unbounded queries without pagination | Cartesian product; row count multiplied by collection size |
| JOIN FETCH + collection + LIMIT | Any paginated query | **Never** — triggers in-memory pagination or `HibernateException` |
| Hibernate batch fetching | Lazy load fallback | Acceptable; reduces N+1 to batched IN queries |

The library's two-phase strategy exists specifically to enable safe collection loading in search results: Phase 2 performs `WHERE id IN (...)` with no LIMIT, making `JOIN FETCH` on collections safe there.

---

## Relation Loading Examples

### Example 1 — Load user with department only

```http
GET /users/42/with?relations=department
```

Request body (alternative POST form):

```json
{
  "relations": ["department"]
}
```

EntityGraph loads `department` eagerly. Result:

```json
{
  "id": 42,
  "name": "Alice",
  "department": {
    "id": 10,
    "name": "Engineering"
  }
}
```

Single SQL: `SELECT u.*, d.* FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.id = 42`

---

### Example 2 — Load user with nested `manager.department`

```json
{
  "relations": ["manager.department"]
}
```

EntityGraph: root node `manager`, subgraph under `manager` with `department`. Loads the user's manager and the manager's department in one query.

```json
{
  "id": 42,
  "name": "Alice",
  "manager": {
    "id": 15,
    "name": "Bob",
    "department": {
      "id": 10,
      "name": "Engineering"
    }
  }
}
```

---

### Example 3 — Load user with roles (ManyToMany)

```http
GET /users/42/with?relations=roles
```

EntityGraph fetches the `roles` collection. Hibernate issues a secondary SELECT for the roles using the join table:

```sql
SELECT r.* FROM roles r
JOIN user_roles ur ON r.id = ur.role_id
WHERE ur.user_id = 42
```

Result:

```json
{
  "id": 42,
  "name": "Alice",
  "roles": [
    {"id": 1, "name": "ADMIN"},
    {"id": 3, "name": "EDITOR"}
  ]
}
```

---

### Example 4 — Search with relations triggering two-phase

```json
POST /users/search
{
  "pagination": {"page": 1, "pageSize": 20},
  "relations": ["department", "roles"],
  "oper": {"and": ["status|=|ACTIVE"]}
}
```

`TwoPhaseDetector` sees `roles` (ManyToMany) + pagination → two-phase activated:

- Phase 1: `SELECT DISTINCT u.id FROM users u WHERE u.status = 'ACTIVE' LIMIT 20 OFFSET 0`
- Phase 2: `SELECT u.*, d.*, r.* FROM users u LEFT JOIN departments d ... LEFT JOIN user_roles ur ... LEFT JOIN roles r ... WHERE u.id IN (1, 2, ..., 20)`

---

### Example 5 — Combining relation loading with filtering

```json
POST /employees/search
{
  "pagination": {"page": 1, "pageSize": 10},
  "relations": ["department"],
  "oper": {
    "and": [
      "department.name|=|Engineering",
      "active|=|true"
    ]
  },
  "orderby": [{"name": "asc"}]
}
```

`department` is to-one → single-phase. Filter and ordering both apply. `toView()` can safely access `entity.getDepartment()` because it was loaded by the EntityGraph.

---

### Example 6 — Multi-level nested: `manager.department.company`

```json
{
  "relations": ["manager.department.company"]
}
```

`findByIdWithRelations()` splits on the first dot: `segments[0] = "manager"`, `segments[1] = "department.company"`. The second level subgraph is added for `department`, and within it, `company` is loaded. This covers three levels of nesting in a single `em.find()` call.

```json
{
  "id": 42,
  "name": "Alice",
  "manager": {
    "id": 15,
    "name": "Bob",
    "department": {
      "id": 10,
      "name": "Engineering",
      "company": {
        "id": 1,
        "name": "Acme Corp"
      }
    }
  }
}
```

Note: the `@AllowedRelations` annotation must list `"manager.department.company"` explicitly for this to be permitted.
