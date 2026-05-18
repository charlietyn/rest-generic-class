# Chapter 16 — Anti-Patterns and Pitfalls

This chapter documents the twelve most common mistakes made when using `rest-generic-class`.
Each entry follows a consistent structure: the wrong code, a precise explanation of the exact
failure mode (including error class, HTTP status, and performance impact), and the correct
replacement.

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

This pattern has three distinct, compounding failure modes:

**1. `LazyInitializationException` at serialization time.**
Jackson serializes the returned object after the JPA session that loaded it has closed. Any
`@OneToMany` or `@ManyToMany` field backed by a Hibernate proxy collection has not been
initialized. When Jackson touches the proxy, it attempts to issue a query against a closed
session. Hibernate throws `LazyInitializationException`. The client receives an HTTP 500
with no useful error message and no indication of which field caused the problem.

**2. Infinite JSON serialization cycle from bidirectional associations.**
`UserEntity` has `@ManyToOne DepartmentEntity department` and `DepartmentEntity` has
`@OneToMany List<UserEntity> users`. Jackson serializes `User → department → users[0] →
department → users[0] → ...` until the call stack exhausts and a `StackOverflowError` terminates
the thread, or until Jackson's cycle-detection limit triggers a `JsonMappingException: Infinite
recursion (StackOverflowError)`.

**3. Exposure of every mapped column.**
The entity typically carries `passwordHash`, `isAdmin`, `internalFlag`, `auditCreatedBy`,
`deletedAt`, and other fields that must never appear in an API response. There is no column-level
filter between the entity and the JSON output. Sensitive data is leaked by default.

### Correct

Return a DTO record or a Blaze-Persistence Entity View. The DTO is the minimum viable fix:

```java
// Projection — only the fields safe to expose
public record UserDto(Long id, String name, String email, String status) {}

// In BaseQueryService subclass:
@Override
public UserDto toView(UserEntity entity) {
    return new UserDto(
        entity.getId(),
        entity.getName(),
        entity.getEmail(),
        entity.getStatus()
    );
}
```

For richer projections with nested associations, use a Blaze-Persistence Entity View
(see Chapter 13). The view interface declares exactly which fields are projected and fetches only
those columns from the database — `isAdmin` and `passwordHash` are never loaded because they are
not declared in the interface:

```java
@EntityView(UserEntity.class)
public interface UserView {
    Long getId();
    String getName();
    String getEmail();
    // isAdmin intentionally NOT declared — never fetched, never serialized
}
```

The `toView(E entity)` method in `BaseQueryService` is the correct conversion boundary. Perform
all mapping there and return the view type as the generic parameter `V`.

---

## AP-2: Skipping `@AllowedRelations`

### Wrong

```java
@Entity
@Table(name = "users")
// @AllowedRelations annotation is absent entirely
public class UserEntity {
    @OneToMany(mappedBy = "user")
    private List<OrderEntity> orders;
}
```

### Why It Is Wrong

**With `strict-relations=true` (the default):** `PathValidator.validateRelationPath()` consults
`AllowlistRegistry` for every relation path in the request. With no annotation present, the
registry has no entry for `UserEntity`. Every request that includes a `relations` array or a
relation-scoped `oper` key throws `InvalidRelationException` → HTTP 400. The feature is
completely unusable.

**The real risk if `strict-relations=false`:** With the flag disabled, the validator skips the
allowlist check. An attacker sends:

```json
{
  "relations": ["orders.items.product.category.parentCategory.topCategory.vendor.country"]
}
```

Each dot-segment resolves to an additional JOIN or subquery. A seven-segment path on a table with
10,000 users multiplies rows at every join level. Combined with no pagination limit, this is a
denial-of-service vector that requires no authentication and no special privilege — only knowledge
of the JPA model, which is often inferable from error messages.

### Correct

Always annotate every entity with explicit, minimal allowlists. List only the paths the
application actually needs:

```java
@Entity
@Table(name = "users")
@AllowedRelations({"department", "roles"})
@AllowedOrderBy({"name", "createdAt", "email", "department.name"})
public class UserEntity { ... }
```

If a relation is required by a single internal service but should not be universally accessible,
register it programmatically via `AllowlistRegistry` in that service's configuration class
rather than on the annotation visible to all consumers:

```java
@Configuration
public class InternalAuditConfig {
    @Autowired
    void configure(AllowlistRegistry registry) {
        // auditLog is only loadable from the internal audit service
        registry.registerRelations(UserEntity.class,
            Set.of("department", "roles", "auditLog"));
    }
}
```

---

## AP-3: Using the Default `mapData()` for Sensitive Entities

### Wrong

```java
@Service
public class UserService extends BaseCrudService<UserEntity, UserDto, Long> {

    @Override
    public Class<UserEntity> entityClass() { return UserEntity.class; }

    @Override
    public UserDto toView(UserEntity e) { return new UserDto(e); }

    // mapData() NOT overridden — default reflection-based assignment is used
}
```

### Why It Is Wrong

The default `mapData(E entity, Map<String, Object> data)` uses reflection to iterate over the
map's keys and assign each key to the matching field on the entity. No field-level access control
exists. A malicious client sends:

```json
POST /api/users
{
  "name": "hacker",
  "isAdmin": true,
  "password": "known_bcrypt_string",
  "internalFlags": 7
}
```

All four fields are present on `UserEntity`. The reflection loop finds each one and sets it.
The entity is persisted with `isAdmin=true`, an attacker-controlled password hash, and internal
flags rewritten. No error is thrown; the response confirms the write succeeded.

This is a **mass-assignment vulnerability** — the same class of bug that enabled several
well-known breaches in Rails and Laravel applications.

### Correct

Override `mapData()` with an explicit whitelist of caller-writable fields. Validate values
before assignment:

```java
@Override
protected void mapData(UserEntity entity, Map<String, Object> data) {
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
    // isAdmin, password, internalFlags — intentionally absent
}
```

For privileged fields (e.g., `isAdmin`) that only an admin should write, create a dedicated
endpoint with its own `@PreAuthorize` guard rather than routing through the generic `update()`
method.

---

## AP-4: Disabling `fail_on_pagination_over_collection_fetch`

### Wrong

```yaml
# application.yml — DANGEROUS
spring:
  jpa:
    properties:
      hibernate.query.fail_on_pagination_over_collection_fetch: false
```

### Why It Is Wrong

Consider this request:

```json
{
  "relations": ["roles"],
  "pagination": {"page": 5, "pageSize": 20}
}
```

`UserEntity` has a `@ManyToMany` with `RoleEntity`. With this flag disabled, Hibernate silently
falls back to **in-memory pagination**:

1. Hibernate issues `SELECT users JOIN roles` with **no `LIMIT` or `OFFSET`** in SQL.
2. All matching users and their roles are loaded into Java heap — potentially 50,000+ rows.
3. Hibernate slices rows 80–100 in Java memory to return page 5.

Consequences:
- **Wrong results.** Row order in heap is non-deterministic after a JOIN without ORDER BY. Pages
  can overlap or contain gaps with no error returned to the client.
- **Out-of-memory risk.** 10,000 users × 3 average roles = 30,000 objects loaded before any
  slicing. A wider dataset causes an OutOfMemoryError.
- **No warning, no exception, no log entry.** The response looks identical to a correct paginated
  response. The issue is invisible until load testing or a production incident.

Hibernate introduced this flag precisely to prevent this class of silent failure. Disabling it
removes the safety net.

### Correct

Keep the flag at `true` in every environment, including development and CI:

```yaml
spring:
  jpa:
    properties:
      hibernate.query.fail_on_pagination_over_collection_fetch: true  # always — non-negotiable
```

`TwoPhaseDetector` in `rest-generic-class` automatically detects any request that combines a
to-many relation with pagination, and routes it to the two-phase strategy (Chapter 11).
Phase 1 fetches a page of scalar IDs (no collection JOIN, no Hibernate warning).
Phase 2 fetches those entities with their relations by ID. The database performs all pagination;
nothing is done in Java memory.

---

## AP-5: Missing `@Transactional` on Custom Service Methods

### Wrong

```java
@Service
public class UserService extends BaseCrudService<UserEntity, UserDto, Long> {

    public UserDto promoteToAdmin(Long id) {  // NOT @Transactional
        UserEntity user = em.find(UserEntity.class, id);
        user.setIsAdmin(true);
        // em.flush() not called — change may or may not persist
        return toView(user);
    }
}
```

### Why It Is Wrong

Outside an active transaction, `em.find()` may return a **detached** entity instance. Detached
entities are not associated with any persistence context. Calling `setIsAdmin(true)` on a
detached entity mutates the in-memory Java object, but Hibernate's dirty-checking mechanism does
not observe it because there is no open unit-of-work tracking the change. No `UPDATE` statement
is generated. The method returns a DTO showing `isAdmin: true`; the database row still holds
`false`.

The failure is environment-dependent. In some container configurations, a transaction-scoped
persistence context happens to be open, making the entity appear managed and the bug disappear
locally. In production with a different transaction manager configuration, the write is silently
lost. Tests pass; production is wrong.

### Correct

Annotate every custom write method with `@Transactional`:

```java
@Transactional
public UserDto promoteToAdmin(Long id) {
    UserEntity user = em.find(UserEntity.class, id);
    if (user == null) {
        throw new EntityNotFoundException("User not found: " + id);
    }
    user.setIsAdmin(true);
    // Hibernate dirty-checking fires at transaction commit — no explicit flush needed
    return toView(user);
}
```

For read-only custom methods that traverse relations, use `@Transactional(readOnly = true)` to
keep the persistence context open during lazy loading while signaling to the persistence provider
that no dirty-checking or flush is needed:

```java
@Transactional(readOnly = true)
public UserDto findWithFullProfile(Long id) {
    return findByIdWithRelations(id, List.of("department", "roles"))
        .orElseThrow(() -> new EntityNotFoundException("User " + id + " not found"));
}
```

---

## AP-6: Pagination Without Ordering — Non-Deterministic Results

### Wrong

```json
{
  "pagination": {"page": 2, "pageSize": 10}
}
```

No `orderby` clause is included.

### Why It Is Wrong

The generated SQL is:

```sql
SELECT * FROM users LIMIT 10 OFFSET 10
```

The SQL standard makes no guarantee about row order when `ORDER BY` is absent. In practice:

- PostgreSQL returns rows in heap-file order, which changes after `VACUUM`, `UPDATE`, or
  autovacuum compaction runs. The same query returns different rows on different days with no
  data changes.
- After a concurrent `INSERT` that lands in the heap before physical offset 10, every subsequent
  page shifts by one row. Page 2 contains a duplicate of the last row on page 1. One row is
  skipped entirely. No error is returned; the client sees a plausible but wrong response.
- After a concurrent `DELETE`, rows shift in the opposite direction. A row that was on page 1 now
  appears on page 2.

This is not a `rest-generic-class` limitation — it is a property of unbounded SQL.

### Correct

Always pair pagination with a stable `orderby`. The primary key is the minimum viable stable
sort. For user-visible sorts, append `id` as a tie-breaker:

```json
{
  "pagination": {"page": 2, "pageSize": 10},
  "orderby": [{"createdAt": "desc"}, {"id": "asc"}]
}
```

`id` is almost always indexed and adds negligible query overhead. The compound sort guarantees
that the same logical row always appears on the same page regardless of concurrent writes.

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

`FilterCompiler` translates each string into a `CriteriaBuilder.equal()` predicate. Two hundred
conditions joined with `OR` produce:

```sql
WHERE id = 1 OR id = 2 OR id = 3 OR ... OR id = 200
```

Most query planners abandon index-based optimization above roughly 5–20 OR disjuncts and fall
back to a sequential scan. Even on a perfectly indexed column, 200 OR predicates produce a query
plan that is materially worse than a single `IN` clause. On a non-indexed column, this is always
a full table scan.

Additionally, `DslParser` enforces `maxConditions` (default 100). A list of 200 conditions throws
`InvalidFilterException: Maximum number of conditions exceeded` before the query is even built.

### Correct

Use the `in` operator, which maps to a single SQL `IN (...)` clause:

```json
{
  "oper": {
    "and": ["id|in|1,2,3,4,5,...,200"]
  }
}
```

The planner can use an index scan or a hash join against the literal list. The prepared statement
is parameterized as a single bind parameter. Statement cache hit rates remain high regardless of
which IDs are requested.

For very large sets (> 1000 IDs, beyond the `in` clause's practical limit), use a dedicated
endpoint that takes the ID list as a request body and queries with a `WHERE id = ANY(?)` or a
temporary table join:

```java
@PostMapping("/by-ids")
public ResponseEntity<List<UserDto>> getByIds(@RequestBody List<Long> ids) {
    return ResponseEntity.ok(service.findAllById(ids));
}
```

---

## AP-8: Relational `orderby` Paths Not Listed in `@AllowedOrderBy`

### Wrong

```java
@Entity
@AllowedRelations({"department"})
// @AllowedOrderBy absent, or does not include "department.name"
public class UserEntity { ... }
```

```json
{
  "orderby": [{"department.name": "asc"}],
  "relations": ["department"],
  "pagination": {"page": 1, "pageSize": 20}
}
```

### Why It Is Wrong

`@AllowedRelations` and `@AllowedOrderBy` are **independent allowlists**. Having a path in
`@AllowedRelations` does not permit ordering by its fields. `OrderByCompiler.compile()` calls
`PathValidator.validateOrderByPath()` for every entry in `orderby`. The validator checks only
`AllowlistRegistry.isOrderByAllowed()`:

```
InvalidRelationException: OrderBy path 'department.name' is not allowed for UserEntity
→ HTTP 400 Bad Request
```

This is intentional. Without the independent check, an attacker could specify:

```json
{"orderby": [{"roles.permissions.resource.internalKey": "asc"}]}
```

This forces a multi-level JOIN through relations not exposed in `@AllowedRelations`, solely for
ordering — bypassing the relation allowlist entirely.

### Correct

List every permitted path in `@AllowedOrderBy`, including relational paths:

```java
@Entity
@AllowedRelations({"department", "roles"})
@AllowedOrderBy({"name", "email", "createdAt", "status", "department.name", "department.code"})
public class UserEntity { ... }
```

Note: `roles.name` is deliberately absent. Including a to-many path in `@AllowedOrderBy`
triggers a scalar correlated subquery for ordering (Chapter 06). Only list to-many paths after
reviewing the performance implications for your data volume.

---

## AP-9: Using `pageSize=10000` for Data Exports

### Wrong

```json
{
  "pagination": {"page": 1, "pageSize": 10000},
  "orderby": [{"id": "asc"}]
}
```

### Why It Is Wrong

`Pagination.of()` clamps `pageSize` to a maximum of 1000. The request above silently becomes
`pageSize=1000`. The caller receives 1,000 records and may incorrectly conclude the full dataset
has only 1,000 rows.

Even at the clamped limit:
- 1,000 JPA entity instances are materialized in the persistence context simultaneously.
- `toView()` is called 1,000 times, producing 1,000 DTO objects in heap.
- The full result set is serialized to a single JSON response body, potentially several megabytes.
- `OFFSET N` scans are not free: PostgreSQL must physically read and discard the first N rows on
  every page. At page 100 (`OFFSET 99,000`), this becomes prohibitively slow even with indexes.

The `search()` endpoint is designed for interactive UI pagination with page sizes of 10–100.
It is architecturally unsuited for bulk export.

### Correct

Create a dedicated streaming export endpoint using JPA `Stream<E>` with a fetch-size hint:

```java
@GetMapping("/export")
@Transactional(readOnly = true)
public void exportCsv(HttpServletResponse response) throws IOException {
    response.setContentType("text/csv");
    response.setHeader("Content-Disposition", "attachment; filename=users.csv");

    try (PrintWriter writer = response.getWriter();
         Stream<UserEntity> stream = userRepository.streamAll()) {
        writer.println("id,name,email,status");
        stream.forEach(u ->
            writer.println(u.getId() + "," + u.getName() + "," +
                           u.getEmail() + "," + u.getStatus()));
    }
}

// In repository:
@Query("SELECT u FROM UserEntity u ORDER BY u.id")
@QueryHints(@QueryHint(name = AvailableHints.HINT_FETCH_SIZE, value = "100"))
Stream<UserEntity> streamAll();
```

The `Stream` approach fetches 100 rows at a time from the JDBC driver, writing them immediately
to the response before fetching the next batch. Heap usage is O(batch-size), not O(total-rows).

---

## AP-10: Using `em.find()` for Foreign Key Assignment

### Wrong

```java
@Override
protected void mapData(UserEntity entity, Map<String, Object> data) {
    if (data.containsKey("departmentId")) {
        Long deptId = ((Number) data.get("departmentId")).longValue();
        // Issues SELECT * FROM departments WHERE id = ? — loads the full row unnecessarily
        DepartmentEntity dept = em.find(DepartmentEntity.class, deptId);
        entity.setDepartment(dept);
    }
}
```

### Why It Is Wrong

`em.find()` issues an immediate `SELECT` to load all mapped columns of `DepartmentEntity`. The
goal is to assign a single foreign key column (`user.department_id = deptId`). No field on
`DepartmentEntity` is read. The fully loaded object is discarded after the assignment.

In a bulk create of 500 users all assigned to the same department, this issues 500 `SELECT`
queries (or 500 / `default-batch-fetch-size` batched queries with batch loading enabled). Even
with the second-level cache, this is 500 cache lookups where zero are needed.

### Correct

`em.getReference()` returns a Hibernate proxy containing only the identifier. No SQL is issued
until a non-identifier field is accessed. Hibernate writes the foreign key column directly from
the proxy's identifier at flush time:

```java
@Override
protected void mapData(UserEntity entity, Map<String, Object> data) {
    if (data.containsKey("departmentId")) {
        Long deptId = ((Number) data.get("departmentId")).longValue();
        // No SELECT issued; proxy carries only deptId
        DepartmentEntity dept = em.getReference(DepartmentEntity.class, deptId);
        entity.setDepartment(dept);
    }
}
```

If the `deptId` does not exist, the foreign key constraint violation fires at flush time during
the `INSERT` or `UPDATE`. This is the correct failure point — the database enforces referential
integrity, not the application layer.

---

## AP-11: Calling `cbf.create(em, ...)` Directly with Spring Boot 3

### Wrong

```java
@Autowired
private EntityManager em;  // jakarta.persistence.EntityManager (Spring Boot 3)

@Autowired
private CriteriaBuilderFactory cbf;

public List<UserView> getReport() {
    // COMPILE ERROR: cbf.create() expects javax.persistence.EntityManager,
    // not jakarta.persistence.EntityManager
    return cbf.create(em, UserEntity.class)
              .fetch("department")
              .getResultList();
}
```

### Why It Is Wrong

Blaze-Persistence's `CriteriaBuilderFactory` was originally compiled against the `javax.*`
namespace. Spring Boot 3 migrated to Jakarta EE 10 (`jakarta.*`). `javax.persistence.EntityManager`
and `jakarta.persistence.EntityManager` are distinct types at the JVM level — neither is a
subtype of the other. The `cbf.create(em, ...)` call does not compile when the Blaze-Persistence
Jakarta EE module is absent, and fails at runtime with a `ClassCastException` if the wrong module
is present.

Additionally, calling `cbf` directly bypasses all `rest-generic-class` allowlist validation,
two-phase detection, and DSL parsing. Relations loaded this way are not subject to
`@AllowedRelations` enforcement.

### Correct

Use `EntityViewSpecificationExecutor` via Spring Data repositories — the supported, Jakarta-safe
integration path (Chapter 13):

```java
// Repository
public interface UserRepository
    extends JpaRepository<UserEntity, Long>,
            EntityViewSpecificationExecutor<UserEntity> {}

// Service — extend BaseQueryService; toView() is handled by the executor
@Service
public class UserService extends BaseQueryService<UserEntity, UserView> {

    @Autowired
    private UserRepository repository;

    @Override
    protected Class<UserEntity> entityClass() { return UserEntity.class; }

    @Override
    protected UserView toView(UserEntity entity) {
        return entityViewManager.convert(entity, UserView.class);
    }
}
```

`EntityViewSpecificationExecutor` integrates with Spring Data's `Pageable` and `Specification`
abstractions. The `Specification<E>` objects that `rest-generic-class` builds internally compose
correctly with Blaze-Persistence Entity Views through this interface, with no `javax`/`jakarta`
namespace conflict.

---

## AP-12: Assuming AND/OR Groups Cannot Be Nested

### Wrong Mental Model

> "I can have one `and` key and one `or` key at the top level of `oper`. Conditions are strings
> inside those arrays. That is the complete structure."

This mental model produces flat, overly broad filters:

```json
{
  "oper": {
    "or": [
      "status|=|ACTIVE",
      "status|=|PENDING",
      "country|=|US",
      "country|=|CA"
    ]
  }
}
```

The intent is "users who are (ACTIVE or PENDING) AND (from US or CA)". The actual result is
"users who are ACTIVE, OR pending, OR from the US, OR from Canada" — which includes inactive
users from Germany who happen to be PENDING, and ACTIVE users from France.

### Why It Is Wrong

A flat OR across all four conditions forms a single disjunction. Any one condition matching is
sufficient to include the row. The intended cross-product filter (status condition AND country
condition) cannot be expressed as a flat OR.

### Correct

`GroupNode` children can themselves be `GroupNode` objects. AND and OR groups can be nested to
any depth up to `filtering.max-depth` (default 5):

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

Compiled SQL:

```sql
WHERE (status = 'ACTIVE' OR status = 'PENDING')
  AND (country = 'US' OR country = 'CA')
```

`DslParser` recognizes a JSON object inside a group's child array as a nested group rather than
a condition string. The following three-level structure is also valid:

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

This expresses:
`((status=ACTIVE AND verified=true) OR (status=PENDING AND createdAt>2024-01-01)) AND deletedAt IS NULL`

Relation-scoped filters support the same nesting inside their subquery group:

```json
{
  "oper": {
    "and": ["status|=|ACTIVE"],
    "department": {
      "and": [
        {"or": ["active|=|true", "legacy|=|true"]},
        "country|=|US"
      ]
    }
  }
}
```

This adds an EXISTS subquery on `department` with a nested AND/OR predicate inside it.
