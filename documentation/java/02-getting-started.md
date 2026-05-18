# rest-generic-class — Getting Started

## Prerequisites

| Requirement           | Minimum Version | Notes                                                       |
|-----------------------|-----------------|-------------------------------------------------------------|
| Java                  | 21              | Records, sealed interfaces, pattern matching in switch      |
| Spring Boot           | 3.3.x           | `jakarta.persistence.*`, auto-configuration v2              |
| Hibernate             | 6.x             | Bundled with Spring Boot 3.3                                |
| Jakarta EE            | 10              | Transitive via Spring Boot 3.3                              |
| Maven or Gradle       | Any current     | Maven examples shown; Gradle equivalents follow the same GAV|

---

## Maven Dependency

Add to your `pom.xml`:

```xml
<dependency>
    <groupId>com.ronu</groupId>
    <artifactId>rest-generic-class</artifactId>
    <version>1.0.0-SNAPSHOT</version>
</dependency>
```

The library declares the following as **optional** dependencies (not transitively required):

- `blaze-persistence-core-api`
- `blaze-persistence-entity-view-api`

If you want Blaze Entity Views as your view layer, add both artifacts explicitly in your own `pom.xml`.

### Gradle

```groovy
implementation 'com.ronu:rest-generic-class:1.0.0-SNAPSHOT'
```

---

## Minimum Viable Project in 4 Steps

### Step 1 — JPA Entity with Allowlist Annotations

Annotate your entity class with `@AllowedRelations` and `@AllowedOrderBy` to declare what the API is permitted to touch.

```java
import com.ronu.restgeneric.annotation.AllowedOrderBy;
import com.ronu.restgeneric.annotation.AllowedRelations;
import jakarta.persistence.*;
import java.util.ArrayList;
import java.util.List;

@Entity
@Table(name = "users")
@AllowedRelations({"department", "roles"})
@AllowedOrderBy({"name", "email", "createdAt", "department.name"})
public class UserEntity {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    private String name;
    private String email;
    private String status;

    @ManyToOne(fetch = FetchType.LAZY)
    @JoinColumn(name = "department_id")
    private DepartmentEntity department;

    @ManyToMany
    @JoinTable(
        name = "user_roles",
        joinColumns = @JoinColumn(name = "user_id"),
        inverseJoinColumns = @JoinColumn(name = "role_id")
    )
    private List<RoleEntity> roles = new ArrayList<>();

    // getters / setters
}
```

Key points:
- `@AllowedRelations` lists which relation names the caller can request for eager loading and which can appear as scoped filters. Anything not listed → HTTP 400.
- `@AllowedOrderBy` lists which field paths (including dot-notation cross-relation paths) may be used in `orderby`. If the annotation is absent, only local scalar fields are sortable.
- Both annotations are read at runtime from `java.lang.Class.getAnnotation(...)` and cached in `AllowlistRegistry`.

---

### Step 2 — DTO Record

Create an immutable view type. This is what callers receive in the response body. The entity itself never crosses the service boundary.

```java
public record UserDto(Long id, String name, String email, String status) {}
```

You can use any POJO, Blaze Entity View, or Projections interface here — the library only requires that `toView(E)` returns `V`.

---

### Step 3 — Service Extending `BaseCrudService`

```java
import com.ronu.restgeneric.query.FilterCompiler;
import com.ronu.restgeneric.query.OrderByCompiler;
import com.ronu.restgeneric.query.QueryPlanCompiler;
import com.ronu.restgeneric.service.BaseCrudService;
import jakarta.persistence.EntityManager;
import org.springframework.stereotype.Service;

@Service
public class UserService extends BaseCrudService<UserEntity, UserDto, Long> {

    public UserService(EntityManager em,
                       QueryPlanCompiler compiler,
                       FilterCompiler filterCompiler,
                       OrderByCompiler orderByCompiler) {
        super(em, compiler, filterCompiler, orderByCompiler);
    }

    @Override
    protected Class<UserEntity> entityClass() {
        return UserEntity.class;
    }

    @Override
    protected UserDto toView(UserEntity e) {
        return new UserDto(e.getId(), e.getName(), e.getEmail(), e.getStatus());
    }
}
```

The four constructor arguments are all auto-configured beans. Spring injects them by type.

You **must** override two methods:
- `entityClass()` — returns `UserEntity.class`. Used for `EntityManager.find`, Criteria `Root`, and metamodel navigation.
- `toView(E)` — maps one entity to its view. Called after every query result.

Optional overrides in `BaseCrudService`:
- `newInstance()` — override to use a custom factory instead of reflection.
- `mapData(E entity, Map<String, Object> data)` — override to use MapStruct, ModelMapper, or manual field mapping for create/update.

---

### Step 4 — Controller Extending `BaseRestController`

```java
import com.ronu.restgeneric.controller.BaseRestController;
import com.ronu.restgeneric.service.BaseCrudService;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RequestMapping("/users")
public class UserController extends BaseRestController<UserEntity, UserDto, Long> {

    private final UserService userService;

    public UserController(UserService userService) {
        this.userService = userService;
    }

    @Override
    protected BaseCrudService<UserEntity, UserDto, Long> service() {
        return userService;
    }
}
```

That is the complete controller. All 10 endpoints are inherited from `BaseRestController`. You can override individual endpoint methods if you need custom behaviour on a per-resource basis.

---

## application.yml Minimum Configuration

```yaml
spring:
  jpa:
    properties:
      hibernate.query.fail_on_pagination_over_collection_fetch: true
      hibernate.default_batch_fetch_size: 32

rest-generic:
  filtering:
    max-depth: 5
    max-conditions: 100
```

**Why `fail_on_pagination_over_collection_fetch: true` is mandatory:**
Without this flag, Hibernate silently performs in-memory pagination when a collection join is present alongside `LIMIT/OFFSET`. It loads every matching row into memory and slices the result in the JVM. The library's `TwoPhaseDetector` avoids this problem by routing to the two-phase strategy, but setting the flag to `true` gives you a hard error (HHH90003004) if the detector is ever bypassed — an important safety net during development.

**Why `default_batch_fetch_size: 32` helps:**
When `toView()` accesses a lazily-loaded collection for each entity in a result page, Hibernate issues one SQL `IN (?)` batch query per 32 entities instead of one query per entity. Without this, a page of 20 entities each with a lazy `roles` collection would fire 20 individual queries.

---

## Full Application Properties Reference

```yaml
rest-generic:
  filtering:
    max-depth: 5
    max-conditions: 100
    strict-relations: true
    allowed-operators:
      - "="
      - "!="
      - "<>"
      - "<"
      - ">"
      - "<="
      - ">="
      - "like"
      - "not like"
      - "ilike"
      - "not ilike"
      - "in"
      - "not in"
      - "between"
      - "not between"
      - "null"
      - "not null"
      - "exists"
      - "not exists"
      - "date"
      - "not date"
      - "regexp"
      - "not regexp"
  hibernate:
    fail-on-pagination-over-collection-fetch: true
    default-batch-fetch-size: 32
  cache:
    enabled: false
    store: redis
    ttl: 60
    cacheable-methods:
      - list_all
      - get_one
    vary-headers:
      - Accept-Language
      - X-Tenant-Id
```

See [03-configuration-reference.md](03-configuration-reference.md) for a deep explanation of every property.

---

## Your First Query

Start the application and send a POST request to `/users/search`:

```http
POST /users/search
Content-Type: application/json

{
  "oper": {
    "and": ["status|=|ACTIVE"]
  },
  "relations": ["department"],
  "orderby": [{"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 10}
}
```

**What happens internally:**

1. `BaseRestController.search()` deserializes the body into `DynamicQueryRequest`.
2. `QueryPlanCompiler.compile()` is called with the request and `UserEntity.class`.
3. `DslParser` parses `oper` → `ConditionNode(path="status", op=EQ, values=["ACTIVE"])`.
4. `AllowlistRegistry.register(UserEntity.class)` reads `@AllowedRelations` and `@AllowedOrderBy`.
5. `PathValidator.validateRelationPath(UserEntity.class, "department")` confirms `"department"` is in the allowlist and uses the JPA metamodel to confirm it is a `ManyToOne` (to-one → two-phase not triggered).
6. `PathValidator.validateOrderByPath(UserEntity.class, "name")` confirms `"name"` is in `@AllowedOrderBy`.
7. `TwoPhaseDetector.requiresTwoPhase()` checks relations — `department` is to-one — returns `false`.
8. `QueryPlan` record is constructed with `requiresTwoPhase=false`.
9. `BaseQueryService.searchSinglePhase()` runs a count query, then a fetch query with `ORDER BY name ASC LIMIT 10 OFFSET 0`.
10. Each `UserEntity` result is passed through `UserService.toView()` → `UserDto`.
11. A Spring Data `PageImpl<UserDto>` is returned.

**Expected response:**

```json
{
  "content": [
    {"id": 1, "name": "Alice", "email": "alice@example.com", "status": "ACTIVE"},
    {"id": 2, "name": "Bob",   "email": "bob@example.com",   "status": "ACTIVE"}
  ],
  "totalElements": 42,
  "totalPages": 5,
  "number": 0,
  "size": 10,
  "first": true,
  "last": false,
  "empty": false
}
```

Note: `number` in the Spring Data Page response is zero-indexed (page 1 → `number: 0`). The `pagination.page` field in the request is 1-indexed.

---

## Troubleshooting Common First-Run Issues

### HTTP 400 — "Relation '...' is not allowed"

**Symptom:** Any request that includes a `relations` value or a relation key in `oper` returns 400 with a message like:

```json
{"success": false, "status": 400, "message": "Relation 'department' is not allowed on UserEntity. Allowed: (none)"}
```

**Cause:** The `@AllowedRelations` annotation is missing from `UserEntity`, or the relation name in the request does not exactly match the value in the annotation.

**Fix:** Add `@AllowedRelations({"department", "roles"})` to the entity class. The values must exactly match the JPA attribute names (field names, not column names).

---

### HTTP 400 — "OrderBy path '...' is not allowed"

**Symptom:** Any request with an `orderby` clause returns 400.

**Cause:** The field name in `orderby` is not in `@AllowedOrderBy`, or `@AllowedOrderBy` is absent and the field uses dot notation.

**Fix:** Add `@AllowedOrderBy({"name", "email", "createdAt"})` to the entity, listing every sortable field. For cross-relation sorting like `department.name`, the full dot-notation path must be listed.

---

### HHH90003004 Warning (or Exception)

**Symptom:** Log output contains `HHH90003004: firstResult/maxResults specified with collection fetch` or an exception is thrown on startup.

**Cause:** `hibernate.query.fail_on_pagination_over_collection_fetch` is not set (or set to `false`), and a query with a to-many join is executing with pagination. The two-phase detector is not routing to the two-phase path, possibly because the relation path is not declared in `@AllowedRelations` or is marked as to-one in the metamodel.

**Fix:**
1. Set `hibernate.query.fail_on_pagination_over_collection_fetch: true` in `application.yml`. This converts the silent in-memory pagination into a hard error so it is caught in development.
2. Verify that the relation is correctly mapped as `@OneToMany` or `@ManyToMany` in the entity — the `TwoPhaseDetector` reads from the JPA metamodel.

---

### `IllegalStateException: Cannot instantiate ...`

**Symptom:** A `create` or `bulkCreate` call throws `IllegalStateException: Cannot instantiate UserEntity`.

**Cause:** The entity class does not have a no-argument constructor accessible to `BaseCrudService.newInstance()`.

**Fix:** Add a public no-argument constructor to the entity, or override `newInstance()` in your service:

```java
@Override
protected UserEntity newInstance() {
    return new UserEntity(); // your own factory
}
```

---

### Relations Not Loaded in Response

**Symptom:** The `department` field appears null in the response even though `"relations": ["department"]` was sent.

**Cause:** `toView(E entity)` in your service does not access `entity.getDepartment()`, so the lazy proxy is never initialized. The `relations` parameter influences entity graph loading but only if `BaseQueryService.findByIdWithRelations` is called. For the search path, relations are fetched via EntityGraph during the phase-2 entity load only if you override `fetchEntities` to apply the graph.

**Fix:** For the simplest approach, access the relation field inside `toView()`:

```java
@Override
protected UserDto toView(UserEntity e) {
    String deptName = e.getDepartment() != null ? e.getDepartment().getName() : null;
    return new UserDto(e.getId(), e.getName(), e.getEmail(), e.getStatus(), deptName);
}
```

Or use `@EntityGraph` on a custom repository method and override `fetchEntities`.
