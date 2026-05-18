# Chapter 16 — Anti-Patterns and Pitfalls

This chapter documents the most common mistakes made when building with `rest-generic-class`. Each entry follows the same structure: the wrong approach, a precise explanation of why it fails, and the correct replacement.

---

## AP-1: Exposing JPA Entities Directly from Controllers

### Wrong

```java
// BAD: Returns JPA entity directly
@GetMapping("/{id}")
public UserEntity getUser(@PathVariable Long id) {
    return em.find(UserEntity.class, id);
}
```

### Why It Is Wrong

This pattern has three compounding failure modes:

1. **LazyInitializationException at serialization time.** Jackson attempts to traverse every field when serializing the returned object. Any `@OneToMany` collection annotated with `FetchType.LAZY` has not been loaded by the time the persistence context closes. Jackson's serializer touches the proxy, the proxy tries to issue a query, but there is no open session. The result is a `LazyInitializationException` that surfaces as an HTTP 500 with no useful message.

2. **Infinite recursion from bidirectional relationships.** `@OneToMany`/`@ManyToOne` pairs each hold a reference to the other. Jackson serializes `User → List<Order>`, then for each `Order` it serializes `Order.user → User`, then `User → List<Order>` again — until a `StackOverflowError` terminates the JVM thread.

3. **Data exposure of every field.** The entity carries every mapped column: `password`, `passwordHash`, `isAdmin`, `internalFlag`, `auditCreatedBy`, `deletedAt`. All are included in the JSON response unless explicitly blocked. Sensitive fields are exposed by accident, not by design.

### Correct

Return a DTO record that contains only what the API consumer needs:

```java
// Good: DTO record — only the fields you choose to expose
public record UserDto(Long id, String name, String email, String status) {}

@GetMapping("/{id}")
public ResponseEntity<UserDto> getUser(@PathVariable Long id) {
    return service.findById(id)
        .map(ResponseEntity::ok)
        .orElse(ResponseEntity.notFound().build());
}
```

For richer projections, use a Blaze-Persistence Entity View (Chapter 13):

```java
@EntityView(UserEntity.class)
public interface UserView {
    Long getId();
    String getName();
    String getEmail();
    // isAdmin intentionally NOT included
}
```

The `toView(E entity)` method in `BaseQueryService` is the correct conversion boundary. Perform the mapping there and return the view type as the generic parameter `V`.

---

## AP-2: Skipping `@AllowedRelations`

### Wrong

```java
@Entity
@Table(name = "users")
// @AllowedRelations annotation is absent
public class UserEntity {
    @OneToMany(mappedBy = "user")
    private List<OrderEntity> orders;
    // ...
}
```

### Why It Is Wrong

**With `strict-relations=true` (the default):** `PathValidator.validateRelationPath()` has no allowlist entry for this entity class. Every request that includes a `relations` key or a relation-scoped `oper` key results in `InvalidRelationException` → HTTP 400. The endpoint is effectively broken for any filtered query.

**With `strict-relations=false`:** The missing annotation means the allowlist is empty, which the validator treats as "all paths permitted". An attacker or a careless client can send:

```json
{
  "relations": ["orders.items.product.category.parentCategory.topCategory"]
}
```

Each dot-segment triggers an additional JOIN or subquery. A six-segment path on a table with 50,000 rows produces a query plan that multiplies row counts at every join level. Combined with pagination disabled, this is a denial-of-service vector with no application-level guard.

### Correct

Always annotate entities with the exact set of paths the application legitimately uses:

```java
@Entity
@Table(name = "users")
@AllowedRelations({"orders", "orders.items", "department"})
@AllowedOrderBy({"name", "createdAt", "department.name"})
public class UserEntity {
    // ...
}
```

Keep the allowlist minimal. If a path is not listed, it cannot be requested. Paths are dot-notation strings; each segment is validated against the JPA metamodel by `PathValidator`.

---

## AP-3: Using the Default `mapData()` for Sensitive Entities

### Wrong

```java
@Service
public class UserService extends BaseCrudService<UserEntity, UserDto, Long> {
    @Override
    public Class<UserEntity> entityClass() { return UserEntity.class; }

    @Override
    public UserDto toView(UserEntity entity) { return new UserDto(entity); }

    // mapData() is NOT overridden — the reflection-based default is used
}
```

The default `mapData(E entity, Map<String, Object> data)` implementation uses reflection to assign every key in the request body to the matching entity field.

### Why It Is Wrong

A malicious request body:

```http
POST /api/users
Content-Type: application/json

{"name": "hacker", "isAdmin": true, "password": "known_hash", "deletedAt": null}
```

The reflection-based default iterates the map keys, finds `isAdmin` on `UserEntity`, and sets it to `true`. It finds `password` and sets it to the raw string. It finds `deletedAt` and sets it to `null` — un-soft-deleting the record. None of this requires a bug; it is how the default works.

This is a mass-assignment vulnerability, identical to the class of bugs that enabled several well-known Rails application breaches.

### Correct

Override `mapData()` with an explicit whitelist of fields the caller is permitted to set:

```java
@Override
protected void mapData(UserEntity entity, Map<String, Object> data) {
    // Only fields a caller is allowed to write
    if (data.containsKey("name")) {
        entity.setName((String) data.get("name"));
    }
    if (data.containsKey("email")) {
        entity.setEmail((String) data.get("email"));
    }
    if (data.containsKey("status")) {
        String status = (String) data.get("status");
        if (!Set.of("ACTIVE", "INACTIVE", "PENDING").contains(status)) {
            throw new IllegalArgumentException("Invalid status: " + status);
        }
        entity.setStatus(status);
    }
    // isAdmin, password, deletedAt — intentionally NOT handled here
}
```

For privileged operations (admin promotion, password reset), create dedicated service methods with their own authorization guards.

---

## AP-4: Disabling `fail_on_pagination_over_collection_fetch`

### Wrong

```yaml
spring:
  jpa:
    properties:
      hibernate.query.fail_on_pagination_over_collection_fetch: false  # DANGEROUS
```

### Why It Is Wrong

Consider a request:

```json
{
  "relations": ["roles"],
  "pagination": {"page": 5, "pageSize": 20}
}
```

`UserEntity` has a `@ManyToMany` with `RoleEntity`. With this flag disabled, Hibernate silently loads the **entire result set** — potentially tens of thousands of `user × role` rows — into Java heap memory. It then performs the pagination slice (`OFFSET 80 LIMIT 20`) in application memory, not in the database.

The consequences are:

- **Wrong results.** Row order in Java memory is nondeterministic after a JOIN without ORDER BY. Pages can overlap or contain gaps.
- **Out-of-memory risk.** A query matching 10,000 users each with 20 roles produces 200,000 objects before any slicing occurs.
- **No indication anything is wrong.** The response looks valid. The `totalElements` count appears correct. The issue is invisible until load testing or a production incident.

Hibernate added this flag specifically to prevent this class of error. The warning it logs (when the flag is `true`) is not a Hibernate bug to be silenced — it is a signal that the two-phase strategy is needed.

### Correct

Leave the flag at its default (`true`) in every environment, including local development and CI:

```yaml
spring:
  jpa:
    properties:
      hibernate.query.fail_on_pagination_over_collection_fetch: true  # always
```

The `TwoPhaseDetector` in `rest-generic-class` detects to-many relations combined with pagination and routes automatically to the two-phase strategy (see Chapter 11). Phase 1 fetches a page of IDs using a scalar query (no collection join, no Hibernate warning). Phase 2 fetches those exact entities with their relations by ID. The database performs all pagination; no data is loaded unnecessarily.

---

## AP-5: Missing `@Transactional` on Custom Service Methods

### Wrong

```java
@Service
public class UserService extends BaseCrudService<UserEntity, UserDto, Long> {

    // NOT annotated with @Transactional
    public UserDto promoteToAdmin(Long id) {
        UserEntity user = em.find(UserEntity.class, id);
        user.setIsAdmin(true);
        // em.flush() not called — dirty check may or may not run
        return toView(user);
    }
}
```

### Why It Is Wrong

Outside an active transaction, `em.find()` may return a **detached** entity instance — one that is no longer associated with any persistence context. Calling `setIsAdmin(true)` on a detached entity modifies the in-memory object but does not schedule any `UPDATE` statement. The method returns the modified DTO, the caller sees `isAdmin: true` in the response, but the database row is unchanged.

The behavior is environment-dependent: in some configurations, the container may create a transaction-scoped context that makes the entity appear managed, leading to inconsistent results across deployment environments. This makes the bug hard to reproduce in development but reliably wrong in production.

### Correct

Annotate every custom service method that performs writes with `@Transactional`:

```java
@Transactional
public UserDto promoteToAdmin(Long id) {
    UserEntity user = em.find(UserEntity.class, id);
    if (user == null) {
        throw new EntityNotFoundException("User not found: " + id);
    }
    user.setIsAdmin(true);
    // Dirty-checking fires at transaction commit — no explicit flush needed
    return toView(user);
}
```

Read-only methods that only call `search()` or `findById()` can use `@Transactional(readOnly = true)` to signal intent and allow the persistence provider to skip flush-on-close.

---

## AP-6: Pagination Without Ordering

### Wrong

```json
{
  "pagination": {"page": 2, "pageSize": 10}
}
```

No `orderby` field is included.

### Why It Is Wrong

The generated SQL is:

```sql
SELECT * FROM users LIMIT 10 OFFSET 10
```

The SQL standard does not define a row order for a `SELECT` without `ORDER BY`. In practice:

- The database returns rows in heap-file order, index scan order, or the order dictated by the query plan — all of which can change between executions.
- After a concurrent `INSERT` or `VACUUM`, the same query may return a different ordering.
- **Page 2 can contain rows that also appeared on page 1.** A row inserted between page 1 and page 2 requests shifts all subsequent rows, causing page 2 to duplicate the last row of page 1.
- **Rows can be silently skipped.** A deletion between requests shifts rows in the opposite direction.

This is not a `rest-generic-class` limitation — it is a fundamental property of unbounded SQL.

### Correct

Always include a stable `orderby` when paginating. The primary key is the minimum viable stable sort:

```json
{
  "pagination": {"page": 2, "pageSize": 10},
  "orderby": [{"id": "asc"}]
}
```

For user-facing sort-by-name with stable secondary ordering:

```json
{
  "pagination": {"page": 2, "pageSize": 10},
  "orderby": [{"name": "asc"}, {"id": "asc"}]
}
```

The secondary `id` sort ensures deterministic ordering when multiple rows share the same `name`.

---

## AP-7: Overloading `oper` with Hundreds of OR Conditions

### Wrong

```json
{
  "oper": {
    "or": [
      "id|=|1",
      "id|=|2",
      "id|=|3",
      "...",
      "id|=|200"
    ]
  }
}
```

### Why It Is Wrong

`FilterCompiler` faithfully translates each string into a JPA `CriteriaBuilder.equal()` predicate. `OR(200 predicates)` compiles to:

```sql
WHERE id = 1 OR id = 2 OR id = 3 OR ... OR id = 200
```

The database query planner sees 200 disjuncts. On a non-indexed column, this is a full table scan. Even on an indexed column, most planners abandon index-based optimization above a threshold (typically 5–20 disjuncts) and fall back to a sequential scan with a bitmap heap scan. Performance degrades compared to a single `IN` clause, which the planner can handle as a range or hash lookup.

Additionally, `DslParser` has a `maxConditions` limit (default 100). A list of 200 conditions throws `InvalidFilterException: Maximum number of conditions exceeded`.

### Correct

Use the `in` operator, which maps to a single `IN (...)` predicate:

```json
{
  "oper": {
    "and": ["id|in|1,2,3,4,5,200"]
  }
}
```

For bulk lookups by a list of IDs that does not fit within the DSL (e.g., 10,000 IDs for a report), use a dedicated endpoint:

```java
@PostMapping("/by-ids")
public ResponseEntity<List<UserDto>> getByIds(@RequestBody List<Long> ids) {
    return ResponseEntity.ok(service.findAllById(ids));
}
```

---

## AP-8: Relational `orderby` Without Entry in `@AllowedOrderBy`

### Wrong

```java
@Entity
@AllowedRelations({"department"})
// @AllowedOrderBy is missing, or does not include the relational path
public class UserEntity { ... }
```

Request:

```json
{
  "orderby": [{"department.name": "asc"}],
  "relations": ["department"]
}
```

### Why It Is Wrong

`OrderByCompiler.compile()` calls `PathValidator.validateOrderByPath()` for every item in `orderby`. The `@AllowedOrderBy` annotation is the sole source of permitted paths. Relational paths such as `department.name` are not derived from `@AllowedRelations` — the two allowlists are independent.

The result is:

```
InvalidRelationException: OrderBy path 'department.name' is not allowed for UserEntity
```

This is intentional: an attacker could request `orderby: [{"roles.permissions.resource.internalKey": "asc"}]` to force a multi-level JOIN solely for ordering, bypassing the `@AllowedRelations` guard.

### Correct

List every path — local and relational — that may appear in `orderby`:

```java
@Entity
@AllowedRelations({"department", "roles"})
@AllowedOrderBy({"name", "email", "createdAt", "department.name", "department.code"})
public class UserEntity { ... }
```

Note that `roles.name` is intentionally absent. Adding it would enable ordering by a to-many relationship, which triggers a scalar correlated subquery (Chapter 06). Only list to-many paths if you have reviewed the performance implications.

---

## AP-9: Using `pageSize=10000` for Data Exports

### Wrong

```json
{
  "pagination": {"page": 1, "pageSize": 10000}
}
```

### Why It Is Wrong

`Pagination.of()` clamps `pageSize` to a maximum of 1000. A request for 10,000 silently becomes a request for 1,000. The caller receives 1,000 records and believes the dataset has 1,000 rows.

Even at the clamped limit of 1,000:

- 1,000 entity instances are loaded into the persistence context simultaneously.
- `toView()` is called 1,000 times, building 1,000 DTO objects in heap memory.
- The full result is serialized to a single JSON response body.
- For wide entities (50+ columns), this is 1,000 × 50 field accesses, 1,000 DTO allocations, and a multi-megabyte HTTP response.

The `search()` endpoint is designed for interactive pagination (page sizes of 10–100). It is not designed for batch data export.

### Correct

Create a dedicated export endpoint that streams results using `JPA ScrollableResults` or Spring Data's `Stream<E>`:

```java
@GetMapping("/export")
public void exportCsv(HttpServletResponse response) throws IOException {
    response.setContentType("text/csv");
    response.setHeader("Content-Disposition", "attachment; filename=users.csv");

    try (PrintWriter writer = response.getWriter();
         Stream<UserEntity> stream = userRepository.streamAll()) {
        writer.println("id,name,email");
        stream.map(u -> u.getId() + "," + u.getName() + "," + u.getEmail())
              .forEach(writer::println);
    }
}
```

This writes rows directly to the HTTP response as they are fetched from the database, with O(1) heap usage per row regardless of result set size.

---

## AP-10: Using `em.find()` Instead of `em.getReference()` for FK Assignment

### Wrong

```java
@Override
protected void mapData(UserEntity entity, Map<String, Object> data) {
    if (data.containsKey("departmentId")) {
        Long deptId = ((Number) data.get("departmentId")).longValue();
        DepartmentEntity dept = em.find(DepartmentEntity.class, deptId);  // issues SELECT
        entity.setDepartment(dept);
    }
}
```

### Why It Is Wrong

`em.find()` issues an immediate `SELECT` statement to load the full `DepartmentEntity` from the database. When the only goal is to assign a foreign key (`user.department_id = ?`), this is wasted I/O. You are loading a full row — including all mapped columns of `DepartmentEntity` — to set a single integer column on `UserEntity`.

In a bulk create of 500 users all assigned to the same department, this issues 500 identical `SELECT` queries (or 500 / batch-fetch-size batched queries). Even with the second-level cache enabled, it is 500 cache lookups where zero would suffice.

### Correct

Use `em.getReference()`, which returns a Hibernate proxy without issuing a query:

```java
@Override
protected void mapData(UserEntity entity, Map<String, Object> data) {
    if (data.containsKey("departmentId")) {
        Long deptId = ((Number) data.get("departmentId")).longValue();
        // Returns a proxy — no SELECT issued unless proxy fields are accessed
        DepartmentEntity dept = em.getReference(DepartmentEntity.class, deptId);
        entity.setDepartment(dept);
    }
}
```

At flush time, Hibernate sets `user.department_id = deptId` directly from the proxy's identifier. No `SELECT` on `departments` is ever issued. If the `deptId` does not exist, the subsequent `INSERT` or `UPDATE` fails with a foreign key constraint violation from the database, which is the correct behavior.

---

## AP-11: Calling `cbf.create(em, ...)` Directly with Spring Boot 3

### Wrong

```java
@Service
public class UserService extends BaseCrudService<UserEntity, UserDto, Long> {

    @Autowired
    private CriteriaBuilderFactory cbf;

    @Autowired
    private EntityManager em;  // jakarta.persistence.EntityManager

    public List<UserDto> customQuery() {
        // COMPILE ERROR: CriteriaBuilderFactory.create() expects
        // javax.persistence.EntityManager, not jakarta.persistence.EntityManager
        return cbf.create(em, UserEntity.class)
                  .getResultList();
    }
}
```

### Why It Is Wrong

Blaze-Persistence's `CriteriaBuilderFactory` was originally compiled against `javax.persistence.EntityManager`. Spring Boot 3 uses the Jakarta EE 10 namespace exclusively: `jakarta.persistence.EntityManager`. These are distinct types — one is not a subtype of the other. The call to `cbf.create(em, ...)` does not compile.

Even with the Blaze-Persistence Jakarta EE module, mixing raw `cbf` calls with Spring-managed entity managers can break transaction propagation: the `EntityManager` Spring injects is a thread-bound proxy that participates in the Spring transaction. Calling Blaze-Persistence outside of that context creates a second, uncoordinated persistence context.

### Correct

Use the `EntityViewSpecificationExecutor` via Spring Data repositories, which is the supported integration path (Chapter 13):

```java
public interface UserRepository
    extends JpaRepository<UserEntity, Long>,
            EntityViewSpecificationExecutor<UserEntity> {}

@Service
public class UserService extends BaseCrudService<UserEntity, UserView, Long> {

    @Autowired
    private UserRepository userRepository;

    @Override
    public UserView toView(UserEntity entity) {
        // Blaze-Persistence handles the mapping internally via EntityViewSpecificationExecutor
        // No direct cbf.create() call needed
        return entityViewManager.convert(entity, UserView.class);
    }
}
```

`EntityViewSpecificationExecutor` integrates with Spring Data's `Pageable` and `Specification` abstractions. `rest-generic-class` builds `Specification<E>` objects internally; they compose correctly with Blaze-Persistence Entity Views through this interface without any `javax`/`jakarta` conflict.

---

## AP-12: Assuming `oper` Supports Only One Level of Nesting

### Wrong mental model

> "The top level is AND or OR. Inside that, I can put conditions. That's all."

This leads to structures like:

```json
{
  "oper": {
    "or": ["status|=|ACTIVE", "status|=|PENDING", "country|=|US", "country|=|CA"]
  }
}
```

This expresses `(status=ACTIVE OR status=PENDING OR country=US OR country=CA)` — a single flat OR across all conditions, which is probably not the intended query.

### Why It Is Wrong

The flat OR is logically incorrect when the intent is "active or pending status, AND located in the US or Canada". The flat OR matches any user who is active, or pending, or in the US, or in Canada — including inactive users in Europe who have `status=PENDING`.

### Correct

`GroupNode` children can themselves be `GroupNode` objects, enabling arbitrary nesting. Build the correct Boolean expression:

```json
{
  "oper": {
    "and": [
      {"or": ["status|=|ACTIVE", "status|=|PENDING"]},
      {"or": ["country|=|US", "country|=|CA"]}
    ]
  }
}
```

`DslParser` recognizes a JSON object inside a group's child list as a nested group rather than a condition string. This produces `AND(OR(status=ACTIVE, status=PENDING), OR(country=US, country=CA))`.

More complex structures — AND containing OR containing AND — are supported up to `filtering.max-depth` (default 5). For practical queries, nesting rarely exceeds three levels. If you find yourself building a deeply nested `oper`, consider whether a stored procedure or a dedicated query method would be more maintainable.

```json
{
  "oper": {
    "and": [
      {"or": [
        {"and": ["status|=|ACTIVE", "verified|=|true"]},
        {"and": ["status|=|PENDING", "createdAt|>|2024-01-01"]}
      ]},
      "deletedAt|null|"
    ]
  }
}
```

This expresses: `((status=ACTIVE AND verified=true) OR (status=PENDING AND createdAt>2024-01-01)) AND deletedAt IS NULL`.
