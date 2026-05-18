# 15. Real-World Scenarios

This chapter presents 8 complete, production-realistic examples showing how to use `rest-generic-class` across different domains. Each scenario includes the full request body, relevant Java code, the execution path chosen, expected SQL, and a cURL example.

---

## Scenario 1: E-Commerce Product Search

### Context

An online store exposes a product catalogue API. Products belong to a single category (`@ManyToOne`) and can have multiple images (`@OneToMany`). The storefront needs a paginated, filterable product listing.

### Entity and Configuration

```java
@Entity
@Table(name = "products")
@AllowedRelations({"category", "images"})
@AllowedOrderBy({"price", "name", "createdAt", "category.name"})
public class ProductEntity {
    @Id @GeneratedValue Long id;
    String name;
    String status;
    BigDecimal price;

    @ManyToOne(fetch = FetchType.LAZY)
    CategoryEntity category;

    @OneToMany(mappedBy = "product", fetch = FetchType.LAZY)
    List<ProductImageEntity> images;
}
```

### POST /search Body

```json
{
  "oper": {
    "and": ["status|=|ACTIVE", "price|between|10.00,200.00"],
    "category": {"and": ["name|=|Electronics"]}
  },
  "relations": ["category", "images"],
  "orderby": [{"price": "asc"}, {"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 12}
}
```

### Execution Path

**Two-phase.** The `images` relation is `@OneToMany` (to-many). Pagination is present. `TwoPhaseDetector` inspects the metamodel, finds that `images` is a `PluralAttribute`, and routes to `searchTwoPhase()`.

### Expected SQL

```sql
-- Phase 1 (count):
SELECT COUNT(p.id)
FROM products p
LEFT JOIN categories c ON p.category_id = c.id
WHERE p.status = 'ACTIVE'
  AND p.price BETWEEN 10.00 AND 200.00
  AND c.name = 'Electronics'

-- Phase 1 (IDs):
SELECT DISTINCT p.id
FROM products p
LEFT JOIN categories c ON p.category_id = c.id
WHERE p.status = 'ACTIVE'
  AND p.price BETWEEN 10.00 AND 200.00
  AND c.name = 'Electronics'
ORDER BY p.price ASC, p.name ASC
LIMIT 12 OFFSET 0

-- Phase 2 (full entities):
SELECT p.*, c.*, i.*
FROM products p
LEFT JOIN categories c ON p.category_id = c.id
LEFT JOIN product_images i ON i.product_id = p.id
WHERE p.id IN (3, 7, 11, 22, 35, 41, 58, 63, 71, 82, 94, 107)
ORDER BY p.price ASC, p.name ASC
```

### cURL

```bash
curl -X POST http://localhost:8080/api/v1/products/search \
  -H "Content-Type: application/json" \
  -d '{
    "oper": {
      "and": ["status|=|ACTIVE", "price|between|10.00,200.00"],
      "category": {"and": ["name|=|Electronics"]}
    },
    "relations": ["category", "images"],
    "orderby": [{"price": "asc"}, {"name": "asc"}],
    "pagination": {"page": 1, "pageSize": 12}
  }'
```

---

## Scenario 2: User Management with Role-Based Filtering

### Context

A SaaS admin panel needs to list users who have either ADMIN or MANAGER roles and whose email belongs to a specific company domain. Users have a department (`@ManyToOne`) and roles (`@ManyToMany`).

### POST /search Body

```json
{
  "oper": {
    "and": ["email|ilike|%@acme.com"],
    "roles": {"or": ["name|=|ADMIN", "name|=|MANAGER"]}
  },
  "relations": ["roles", "department"],
  "orderby": [{"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 25}
}
```

### Entity and Configuration

```java
@Entity
@Table(name = "users")
@AllowedRelations({"roles", "department"})
@AllowedOrderBy({"name", "email", "createdAt", "department.name"})
public class UserEntity {
    @Id @GeneratedValue Long id;
    String name;
    String email;
    String status;

    @ManyToOne(fetch = FetchType.LAZY)
    DepartmentEntity department;

    @ManyToMany(fetch = FetchType.LAZY)
    @JoinTable(name = "user_roles")
    List<RoleEntity> roles;
}
```

### Controller

```java
@RestController
@RequestMapping("/api/v1/users")
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

### Execution Path

**Two-phase.** The `roles` relation is `@ManyToMany` (to-many). Phase 1 returns 25 user IDs matching the email domain and role filter. Phase 2 loads those users with their roles and department.

### Expected SQL

```sql
-- Phase 1 (IDs) — EXISTS subquery for roles filter:
SELECT DISTINCT u.id
FROM users u
WHERE u.email ILIKE '%@acme.com'
  AND EXISTS (
      SELECT 1 FROM user_roles ur
      JOIN roles r ON ur.role_id = r.id
      WHERE ur.user_id = u.id
        AND (r.name = 'ADMIN' OR r.name = 'MANAGER')
  )
ORDER BY u.name ASC
LIMIT 25 OFFSET 0

-- Phase 2:
SELECT u.*, d.*, r.*
FROM users u
LEFT JOIN departments d ON u.department_id = d.id
LEFT JOIN user_roles ur ON ur.user_id = u.id
LEFT JOIN roles r ON ur.role_id = r.id
WHERE u.id IN (4, 9, 15, 23, ...)
ORDER BY u.name ASC
```

### cURL

```bash
curl -X POST http://localhost:8080/api/v1/users/search \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "oper": {
      "and": ["email|ilike|%@acme.com"],
      "roles": {"or": ["name|=|ADMIN", "name|=|MANAGER"]}
    },
    "relations": ["roles", "department"],
    "orderby": [{"name": "asc"}],
    "pagination": {"page": 1, "pageSize": 25}
  }'
```

---

## Scenario 3: Reporting Dashboard — Date Range Query

### Context

An e-commerce finance team needs to export orders for Q1 2026. Orders have a customer (`@ManyToOne`) and line items (`@OneToMany`). For this report, only the order header and customer data are needed — no line items — so `relations: ["customer"]` only.

### POST /search Body

```json
{
  "oper": {
    "and": [
      "createdAt|between|2026-01-01,2026-03-31",
      "status|in|PAID,DELIVERED,SHIPPED",
      "totalAmount|>=|100"
    ]
  },
  "relations": ["customer"],
  "orderby": [{"createdAt": "desc"}],
  "pagination": {"page": 1, "pageSize": 100}
}
```

### Execution Path

**Single-phase.** The only requested relation is `customer`, which is `@ManyToOne` (to-one). There is no to-many relation in the request, so `TwoPhaseDetector` returns `false`. The query is a standard paginated SELECT with a JOIN to the `customers` table.

### Expected SQL

```sql
-- Single-phase (count):
SELECT COUNT(o.id)
FROM orders o
WHERE o.created_at BETWEEN '2026-01-01' AND '2026-03-31'
  AND o.status IN ('PAID', 'DELIVERED', 'SHIPPED')
  AND o.total_amount >= 100

-- Single-phase (data):
SELECT o.*, c.*
FROM orders o
LEFT JOIN customers c ON o.customer_id = c.id
WHERE o.created_at BETWEEN '2026-01-01' AND '2026-03-31'
  AND o.status IN ('PAID', 'DELIVERED', 'SHIPPED')
  AND o.total_amount >= 100
ORDER BY o.created_at DESC
LIMIT 100 OFFSET 0
```

Two queries total. Memory usage is bounded by the page size of 100 orders.

### cURL

```bash
curl -X POST http://localhost:8080/api/v1/orders/search \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{
    "oper": {
      "and": [
        "createdAt|between|2026-01-01,2026-03-31",
        "status|in|PAID,DELIVERED,SHIPPED",
        "totalAmount|>=|100"
      ]
    },
    "relations": ["customer"],
    "orderby": [{"createdAt": "desc"}],
    "pagination": {"page": 1, "pageSize": 100}
  }'
```

---

## Scenario 4: Admin Bulk Operations

### Context

An admin panel needs to suspend a batch of users who violated terms of service, and separately delete a set of test accounts. The base controller handles both operations without any custom code.

### Bulk Update — Suspend Users

```bash
curl -X PUT http://localhost:8080/api/v1/users/bulk \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '[
    {"id": 101, "status": "SUSPENDED"},
    {"id": 102, "status": "SUSPENDED"},
    {"id": 103, "status": "SUSPENDED"},
    {"id": 104, "status": "ACTIVE"}
  ]'
```

Response: `200 OK` with a list of 4 updated `UserDto` objects.

### Bulk Delete — Remove Test Accounts

```bash
curl -X DELETE http://localhost:8080/api/v1/users/bulk \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '[201, 202, 203, 204, 205]'
```

Response: `204 No Content`.

### Service Implementation with mapData Override

```java
@Service
public class UserService extends BaseCrudService<UserEntity, UserDto, Long> {

    @Override
    protected void mapData(UserEntity entity, Map<String, Object> data) {
        // Only allow these fields from bulk or single updates
        if (data.containsKey("status"))    entity.setStatus((String) data.get("status"));
        if (data.containsKey("name"))      entity.setName((String) data.get("name"));
        if (data.containsKey("email"))     entity.setEmail((String) data.get("email"));
        // id is used for lookup only — never written to the entity here
    }

    @Override
    protected UserDto toView(UserEntity e) {
        return new UserDto(e.getId(), e.getName(), e.getEmail(), e.getStatus());
    }
}
```

### Cascade and Lifecycle Hook Considerations

The `DELETE /bulk` implementation loads each entity individually before removing it:

```java
// Inside BaseCrudService.bulkDelete(List<ID> ids):
ids.forEach(id -> {
    E entity = findEntityOrThrow(id);  // findById → throws if not found
    em.remove(entity);                  // triggers @PreRemove, cascades
});
```

This means:

- `@PreRemove` lifecycle callbacks fire for every deleted entity
- `CascadeType.REMOVE` is respected — child entities are removed if configured
- Soft-delete hooks (if `@PreRemove` sets `deletedAt`) work correctly
- If any entity in the list does not exist, the entire operation fails and rolls back (within `@Transactional`)

---

## Scenario 5: Department-Scoped User List with To-One OrderBy

### Context

A manager's dashboard shows all users in active, high-budget departments, sorted by department name then user name. The `department` relation is `@ManyToOne` — to-one — so single-phase applies even with pagination.

### POST /search Body

```json
{
  "oper": {
    "department": {
      "and": ["active|=|true", "budget|>=|50000"]
    }
  },
  "relations": ["department"],
  "orderby": [{"department.name": "asc"}, {"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 20}
}
```

### Execution Path

**Single-phase.** `department` is `@ManyToOne`. `TwoPhaseDetector` finds no to-many relations in the request and returns `false`.

### OrderByCompiler Behaviour for To-One Paths

`OrderByCompiler` inspects whether each orderby path leads to a to-one or to-many relation:

- To-one path (`department.name`): produces a `Sort` object (`Sort.by("department.name").ascending()`) which Spring Data resolves via a standard JOIN in the query.
- To-many path (if present): produces a `Specification<E>` subquery for ordering, because a JOIN would multiply rows.

In this scenario, `department.name` is to-one, so `OrderByCompiler.Result` contains a `Sort` — no subquery is needed.

### Expected SQL

```sql
-- Count:
SELECT COUNT(u.id)
FROM users u
LEFT JOIN departments d ON u.department_id = d.id
WHERE d.active = true
  AND d.budget >= 50000

-- Data:
SELECT u.*, d.*
FROM users u
LEFT JOIN departments d ON u.department_id = d.id
WHERE d.active = true
  AND d.budget >= 50000
ORDER BY d.name ASC, u.name ASC
LIMIT 20 OFFSET 0
```

### cURL

```bash
curl -X POST http://localhost:8080/api/v1/users/search \
  -H "Content-Type: application/json" \
  -d '{
    "oper": {"department": {"and": ["active|=|true", "budget|>=|50000"]}},
    "relations": ["department"],
    "orderby": [{"department.name": "asc"}, {"name": "asc"}],
    "pagination": {"page": 1, "pageSize": 20}
  }'
```

---

## Scenario 6: Multi-Tenant SaaS — Custom Service Override

### Context

A SaaS platform stores all tenants' data in a shared database with a `tenant_id` column on each table. Every query must be scoped to the current tenant, regardless of what the client sends. The tenant ID is stored in a `ThreadLocal` by a request filter.

### TenantContext Utility

```java
public class TenantContext {
    private static final ThreadLocal<String> current = new ThreadLocal<>();
    public static String get() { return current.get(); }
    public static void set(String tenantId) { current.set(tenantId); }
    public static void clear() { current.remove(); }
}
```

### Tenant-Aware Service

```java
@Service
public class TenantAwareUserService extends BaseCrudService<UserEntity, UserDto, Long> {

    @Override
    protected long count(Specification<UserEntity> spec) {
        return super.count(Specification.where(tenantFilter()).and(spec));
    }

    @Override
    protected List<Object> fetchIds(Specification<UserEntity> spec,
                                     OrderByCompiler.Result<UserEntity> order,
                                     Pagination pg) {
        return super.fetchIds(Specification.where(tenantFilter()).and(spec), order, pg);
    }

    @Override
    protected List<UserEntity> fetchEntities(Specification<UserEntity> spec,
                                              OrderByCompiler.Result<UserEntity> order,
                                              Pagination pg) {
        return super.fetchEntities(Specification.where(tenantFilter()).and(spec), order, pg);
    }

    @Override
    protected void mapData(UserEntity entity, Map<String, Object> data) {
        // Force tenantId to current tenant on every create — never trust the client
        entity.setTenantId(TenantContext.get());
        if (data.containsKey("name"))  entity.setName((String) data.get("name"));
        if (data.containsKey("email")) entity.setEmail((String) data.get("email"));
    }

    private Specification<UserEntity> tenantFilter() {
        return (root, q, cb) ->
            cb.equal(root.get("tenantId"), TenantContext.get());
    }
}
```

### Why Override All Three Methods

Overriding only `fetchEntities()` would leave `count()` and `fetchIds()` unscoped, potentially leaking counts or IDs from other tenants. All three methods that touch the database must apply the tenant filter to ensure complete isolation.

### Request — Normal DSL, Transparent Tenant Isolation

```json
{
  "oper": {"and": ["status|=|ACTIVE"]},
  "orderby": [{"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 20}
}
```

The client sends a normal query. The tenant predicate is injected transparently by the service layer.

### Expected SQL

```sql
SELECT COUNT(u.id)
FROM users u
WHERE u.tenant_id = 'acme-corp'
  AND u.status = 'ACTIVE'

SELECT DISTINCT u.id
FROM users u
WHERE u.tenant_id = 'acme-corp'
  AND u.status = 'ACTIVE'
ORDER BY u.name ASC
LIMIT 20 OFFSET 0
```

---

## Scenario 7: Full-Text Search Approximation

### Context

A search bar in a user directory should find users whose name, email, or bio contains a given search term. SQL `LIKE`/`ILIKE` approximates full-text search for moderate-scale use cases without requiring a separate search engine.

### POST /search Body

```json
{
  "oper": {
    "or": [
      "name|ilike|%john%",
      "email|ilike|%john%",
      "bio|ilike|%john%"
    ]
  },
  "orderby": [{"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 20}
}
```

### FilterCompiler Output

`FilterCompiler` compiles the `or` array into a single `OR` predicate:

```java
Predicate p = cb.or(
    cb.like(cb.lower(root.get("name")),  "%john%"),
    cb.like(cb.lower(root.get("email")), "%john%"),
    cb.like(cb.lower(root.get("bio")),   "%john%")
);
```

### Execution Path

**Single-phase** (no relations, no to-many). Two queries: count + data.

### Expected SQL

```sql
SELECT COUNT(u.id)
FROM users u
WHERE LOWER(u.name)  LIKE '%john%'
   OR LOWER(u.email) LIKE '%john%'
   OR LOWER(u.bio)   LIKE '%john%'

SELECT u.*
FROM users u
WHERE LOWER(u.name)  LIKE '%john%'
   OR LOWER(u.email) LIKE '%john%'
   OR LOWER(u.bio)   LIKE '%john%'
ORDER BY u.name ASC
LIMIT 20 OFFSET 0
```

### Service and Controller

```java
@RestController
@RequestMapping("/api/v1/users")
public class UserController extends BaseRestController<UserEntity, UserDto, Long> {

    @Override
    protected BaseCrudService<UserEntity, UserDto, Long> service() {
        return userService;
    }

    // Convenience endpoint: GET /users/search?q=john
    @GetMapping("/search")
    public ResponseEntity<Page<UserDto>> search(
            @RequestParam String q,
            @RequestParam(defaultValue = "1") int page,
            @RequestParam(defaultValue = "20") int pageSize) {
        String term = "%" + q.toLowerCase() + "%";
        // Build the OR filter programmatically and forward to POST /search logic
        DynamicQueryRequest req = DynamicQueryRequest.builder()
            .oper(Map.of("or", List.of(
                "name|ilike|" + term,
                "email|ilike|" + term,
                "bio|ilike|" + term
            )))
            .orderby(List.of(Map.of("name", "asc")))
            .pagination(Pagination.of(page, pageSize))
            .build();
        return ResponseEntity.ok(service().search(req));
    }
}
```

### cURL

```bash
# Via POST /search (full DSL)
curl -X POST http://localhost:8080/api/v1/users/search \
  -H "Content-Type: application/json" \
  -d '{"oper":{"or":["name|ilike|%john%","email|ilike|%john%","bio|ilike|%john%"]},"pagination":{"page":1,"pageSize":20}}'

# Via convenience GET endpoint
curl "http://localhost:8080/api/v1/users/search?q=john&page=1&pageSize=20"
```

---

## Scenario 8: Complex Nested Filter — AND of ORs

### Context

A financial compliance system needs to find users who are both verified and active (not deleted), AND belong to one of several country segments, AND are either premium subscribers OR high-credit-score individuals. This requires a nested boolean expression.

### POST /search Body

```json
{
  "oper": {
    "and": [
      "emailVerified|=|true",
      "deletedAt|null|",
      {"or": ["plan|=|PREMIUM", "creditScore|>=|700"]},
      {"or": ["country|=|US", "country|=|CA", "country|=|GB"]}
    ]
  },
  "relations": ["subscription"],
  "orderby": [{"createdAt": "desc"}],
  "pagination": {"page": 1, "pageSize": 50}
}
```

### Execution Path

**Single-phase.** The `subscription` relation is `@ManyToOne` (to-one). No to-many relations are present, so two-phase is not triggered despite the complex filter.

### FilterCompiler AST Compilation

`FilterCompiler` walks the `FilterNode` sealed class hierarchy:

```
AndNode([
  LeafNode("emailVerified", "=", "true"),
  LeafNode("deletedAt", "null", ""),
  OrNode([
    LeafNode("plan", "=", "PREMIUM"),
    LeafNode("creditScore", ">=", "700")
  ]),
  OrNode([
    LeafNode("country", "=", "US"),
    LeafNode("country", "=", "CA"),
    LeafNode("country", "=", "GB")
  ])
])
```

This compiles to a `Specification` that produces:

```java
cb.and(
    cb.equal(root.get("emailVerified"), true),
    cb.isNull(root.get("deletedAt")),
    cb.or(
        cb.equal(root.get("plan"), "PREMIUM"),
        cb.greaterThanOrEqualTo(root.get("creditScore"), 700)
    ),
    cb.or(
        cb.equal(root.get("country"), "US"),
        cb.equal(root.get("country"), "CA"),
        cb.equal(root.get("country"), "GB")
    )
)
```

### Expected SQL

```sql
-- Count:
SELECT COUNT(u.id)
FROM users u
WHERE u.email_verified = true
  AND u.deleted_at IS NULL
  AND (u.plan = 'PREMIUM' OR u.credit_score >= 700)
  AND (u.country = 'US' OR u.country = 'CA' OR u.country = 'GB')

-- Data (with subscription JOIN):
SELECT u.*, s.*
FROM users u
LEFT JOIN subscriptions s ON u.subscription_id = s.id
WHERE u.email_verified = true
  AND u.deleted_at IS NULL
  AND (u.plan = 'PREMIUM' OR u.credit_score >= 700)
  AND (u.country = 'US' OR u.country = 'CA' OR u.country = 'GB')
ORDER BY u.created_at DESC
LIMIT 50 OFFSET 0
```

Note that the `country IN ('US', 'CA', 'GB')` pattern could alternatively be expressed as `"country|in|US,CA,GB"` in a single condition — the nested OR form shown above is equivalent and demonstrates that the DSL supports boolean composition rather than requiring a specific operator for every pattern.

### cURL

```bash
curl -X POST http://localhost:8080/api/v1/users/search \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $COMPLIANCE_TOKEN" \
  -d '{
    "oper": {
      "and": [
        "emailVerified|=|true",
        "deletedAt|null|",
        {"or": ["plan|=|PREMIUM", "creditScore|>=|700"]},
        {"or": ["country|=|US", "country|=|CA", "country|=|GB"]}
      ]
    },
    "relations": ["subscription"],
    "orderby": [{"createdAt": "desc"}],
    "pagination": {"page": 1, "pageSize": 50}
  }'
```

---

## Scenario Summary

| # | Domain | Relations | To-Many? | Execution Path | Queries |
|---|---|---|---|---|---|
| 1 | E-commerce products | category, images | Yes (images) | Two-phase | 3 |
| 2 | User management | roles, department | Yes (roles) | Two-phase | 3 |
| 3 | Order reporting | customer | No | Single-phase | 2 |
| 4 | Admin bulk ops | — | — | Bulk (N saves/deletes) | N |
| 5 | Department-scoped users | department | No | Single-phase | 2 |
| 6 | Multi-tenant SaaS | — | Depends on request | Either, + tenant filter | 2 or 3 |
| 7 | Full-text search | — | No | Single-phase | 2 |
| 8 | Complex nested filter | subscription | No | Single-phase | 2 |
