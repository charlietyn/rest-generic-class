# 14. Security Best Practices

## The Allowlist Model — Default Deny

The library enforces a default-deny policy for all dynamic query capabilities. Nothing is allowed unless explicitly declared.

### @AllowedRelations

Any relation loading must be explicitly permitted on the entity class:

```java
@Entity
@AllowedRelations({"department", "roles", "address"})
public class UserEntity { ... }
```

If a client sends `"relations": ["password", "auditLog", "internalNotes"]` and none of those are in `@AllowedRelations`, every one is rejected with HTTP 400. The error message is deliberately generic — it does not reveal what relations do exist on the entity.

An entity with no `@AllowedRelations` annotation accepts no relation loading at all. This is the correct default for entities that should never expose associations to the API layer.

### @AllowedOrderBy

Ordering by relational paths must also be explicitly permitted:

```java
@Entity
@AllowedRelations({"department", "roles"})
@AllowedOrderBy({"name", "email", "createdAt", "department.name", "department.budget"})
public class UserEntity { ... }
```

If no `@AllowedOrderBy` annotation is present, ordering is limited to scalar (non-relational) fields on the root entity. A client cannot construct an arbitrary JOIN tree by passing deep relational orderby paths.

### Why HTTP 400, Not HTTP 403

Returning HTTP 403 (Forbidden) would confirm that the requested relation or field exists but is not accessible to this caller. HTTP 400 (Bad Request) treats the request as syntactically invalid, revealing nothing about the data model. An attacker probing the schema gets no signal from the response status code.

---

## Never Expose JPA Entities to REST

JPA entities carry significant technical risk when returned directly from REST endpoints.

```java
// WRONG — exposes lazy-init exceptions, serialization cycles, ALL fields
@GetMapping("/{id}")
public UserEntity getUser(@PathVariable Long id) { ... }

// RIGHT — always use a DTO or View
@GetMapping("/{id}")
public UserDto getUser(@PathVariable Long id) { ... }
```

Problems with returning entities directly:

1. **LazyInitializationException** — Jackson attempts to serialize all fields, including lazy-loaded collections. If the `EntityManager` session is closed before serialization completes (the default in Spring), accessing a lazy collection throws `LazyInitializationException`.
2. **Infinite serialization cycles** — Bidirectional associations (e.g., `User` → `Department` → `List<User>`) cause Jackson to follow the cycle until stack overflow or OOM. `@JsonIgnore` and `@JsonManagedReference` are workarounds, not solutions.
3. **Over-exposure** — Every field on the entity, including internal state fields (`version`, `tenantId`, `passwordHash`, `auditLog`), is serialized. A DTO lets you declare exactly what the client sees.
4. **Schema coupling** — Renaming a JPA field changes the API response shape. A DTO layer decouples the database schema from the API contract.

---

## SQL Injection Is Impossible

The entire filter pipeline uses JPA Criteria API with parameterized bindings. String values from the client are never interpolated into SQL strings.

```java
// FilterCompiler always uses cb.equal(path, value) — NEVER string concatenation
// The value goes through coerce() and becomes a JPA parameter binding.

// Example: client sends "name|=|Robert'); DROP TABLE users;--"
// FilterCompiler produces:
Predicate p = cb.equal(root.get("name"), "Robert'); DROP TABLE users;--");
// This becomes a PreparedStatement: WHERE name = ?
// with the parameter value: "Robert'); DROP TABLE users;--"
// The database treats the entire string as a literal value — the SQL injection attempt fails.
```

The `coerce()` step also validates that the value type is compatible with the field type. Sending `"age|=|not-a-number"` when `age` is an `int` column produces HTTP 400, not a database error.

---

## Mass Assignment Prevention

The default `mapData()` implementation maps all keys from the incoming `Map<String, Object>` to entity fields using reflection. This is dangerous for any entity with sensitive or system-managed fields.

```java
// DANGEROUS default — never use for entities with privileged fields:
data.forEach((key, value) -> setField(entity, key, value));
// Attacker sends:
// {"name": "Bob", "isAdmin": true, "roles": [...], "passwordHash": "hacked", "tenantId": "evil"}
// All of these are applied to the entity.
```

**Always override `mapData()` with an explicit field allowlist:**

```java
@Service
public class UserService extends BaseCrudService<UserEntity, UserDto, Long> {

    @Override
    protected void mapData(UserEntity entity, Map<String, Object> data) {
        // Whitelist: only these fields may be set by a client
        if (data.containsKey("name"))        entity.setName((String) data.get("name"));
        if (data.containsKey("email"))       entity.setEmail((String) data.get("email"));
        if (data.containsKey("bio"))         entity.setBio((String) data.get("bio"));
        if (data.containsKey("phone"))       entity.setPhone((String) data.get("phone"));
        if (data.containsKey("avatarUrl"))   entity.setAvatarUrl((String) data.get("avatar_url"));

        // NEVER accept from external data:
        // isAdmin, roles, passwordHash, createdAt, updatedAt, tenantId, version, deletedAt
    }
}
```

For update endpoints, the same rule applies. Even if the client sends `{"id": 42, "isAdmin": true}`, the `mapData()` override ignores `isAdmin` entirely.

---

## Operator Restriction

Restrict the set of allowed filter operators to the minimum your application needs:

```yaml
rest-generic:
  filtering:
    allowed-operators:
      - "="
      - "!="
      - "like"
      - "in"
      - "between"
      - "null"
      - "not null"
```

**Operators to consider removing in production:**

| Operator | Risk |
|---|---|
| `regexp` | Database-specific; potential ReDoS (Regular Expression Denial of Service) with crafted patterns |
| `ilike` | On MySQL and MariaDB, case-insensitive LIKE bypasses indexes; consider disabling |
| `date` | May leak schema information about column types; remove if not needed by any client |

Any attempt to use a disallowed operator returns HTTP 400 (`InvalidOperatorException`).

---

## Depth and Condition Limits as DoS Protection

The filter DSL allows arbitrarily nested `and`/`or` groups and relation subfilters. Without limits, a malicious client could submit:

```json
{
  "oper": {
    "and": [
      {"or": [
        {"and": [{"or": [...1000 conditions...]}]}
      ]}
    ]
  },
  "relations": ["a", "b", "c", "d", "e", "f", "g", "h"]
}
```

This could generate an enormous SQL statement with many JOINs and deeply nested predicate trees, consuming significant database CPU and parse time.

Configure tight limits for production:

```yaml
rest-generic:
  filtering:
    max-depth: 3        # Maximum nesting depth of and/or groups
    max-conditions: 50  # Maximum total number of leaf conditions
```

A depth-10 nested query with 1,000 conditions and 10 relations could generate a SQL statement with hundreds of JOIN predicates. `max-depth: 3` and `max-conditions: 50` make this impossible. Requests that exceed these limits return HTTP 400 (`PathDepthExceededException`).

---

## @FieldsByRole — Role-Based Field Restriction

For entities where different user roles should see different fields, use `@FieldsByRole`:

```java
@Entity
@FieldsByRole({
    @RoleFields(role = "ROLE_USER",  fields = {"id", "name", "email", "department"}),
    @RoleFields(role = "ROLE_ADMIN", fields = {"id", "name", "email", "department",
                                                "salary", "ssn", "internalNotes", "loginHistory"})
})
public class UserEntity { ... }
```

**Enforcement via HandlerInterceptor:**

The `@FieldsByRole` annotation does not self-enforce — it must be read by application code. The recommended approach is a `HandlerInterceptor` that strips disallowed fields from request bodies (for write operations) and from response bodies (for read operations):

```java
@Component
public class FieldsByRoleFilter implements HandlerInterceptorAdapter {

    @Autowired
    private FieldsByRoleResolver resolver;

    @Override
    public boolean preHandle(HttpServletRequest req, HttpServletResponse res, Object handler) {
        Authentication auth = SecurityContextHolder.getContext().getAuthentication();
        Set<String> allowedFields = resolver.resolveAllowedFields(handler, auth.getAuthorities());
        // Attach allowed fields to request attribute for mapData() to check
        req.setAttribute("allowedFields", allowedFields);
        return true;
    }
}
```

Then in `mapData()`:

```java
@Override
protected void mapData(UserEntity entity, Map<String, Object> data) {
    Set<String> allowed = (Set<String>) RequestContextHolder
        .currentRequestAttributes()
        .getAttribute("allowedFields", RequestAttributes.SCOPE_REQUEST);
    data.entrySet().stream()
        .filter(e -> allowed.contains(e.getKey()))
        .forEach(e -> applyField(entity, e.getKey(), e.getValue()));
}
```

---

## Relation Filter Security — Cross-Tenant Data

The DSL allows filtering on related entity fields:

```json
{
  "oper": {
    "department": {"and": ["tenantId|=|evil_tenant"]}
  }
}
```

The library implements relation filters using an `EXISTS` correlated subquery, not a JOIN. The subquery is correlated to the root entity:

```sql
WHERE EXISTS (
    SELECT 1 FROM departments d
    WHERE d.id = u.department_id
      AND d.tenant_id = 'evil_tenant'
)
```

If your root entity is already filtered to the current tenant (via Hibernate `@Filter`, `@TenantId`, or a custom `Specification`), the correlated subquery only reaches department rows accessible from that tenant's users. An attacker cannot use relation filters to probe data in other tenants' departments.

**The key guarantee:** Relation filter subqueries are always correlated — they cannot access unrelated rows in the target table. They can only traverse associations that exist in your JPA mapping.

---

## Multi-Tenancy Pattern

### Option 1: Override BaseQueryService Methods

Override `count()`, `fetchIds()`, and `fetchEntities()` to inject a tenant predicate into every query:

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

    private Specification<UserEntity> tenantFilter() {
        return (root, q, cb) ->
            cb.equal(root.get("tenantId"), TenantContext.getCurrentTenantId());
    }
}
```

This approach is explicit and easy to audit — every database query is guaranteed to include the tenant predicate.

### Option 2: Hibernate @Filter

Use Hibernate 6's `@Filter` annotation for automatic tenant scoping:

```java
@Entity
@Filter(name = "tenantFilter", condition = "tenant_id = :tenantId")
public class UserEntity {
    @Column(name = "tenant_id")
    private String tenantId;
    // ...
}
```

Enable the filter in a request-scoped component:

```java
@Component
public class TenantFilterActivator {

    @Autowired
    private EntityManager em;

    @PostConstruct  // or use a request interceptor
    public void activateFilter() {
        em.unwrap(Session.class)
          .enableFilter("tenantFilter")
          .setParameter("tenantId", TenantContext.getCurrentTenantId());
    }
}
```

### Option 3: Hibernate @TenantId (Hibernate 6+)

Hibernate 6 introduces first-class multi-tenancy via `@TenantId`:

```java
@Entity
public class UserEntity {
    @TenantId
    private String tenantId;
    // Hibernate automatically appends tenant_id = :currentTenant to all queries
}
```

---

## Security Audit Checklist

Use this checklist before deploying any `rest-generic-class` powered API to production:

1. [ ] All entities have explicit `@AllowedRelations` — or no annotation if relation loading is not needed
2. [ ] All entities have explicit `@AllowedOrderBy` — or no annotation if only scalar ordering is needed
3. [ ] `mapData()` is overridden with an explicit field allowlist for every write-enabled entity
4. [ ] `max-depth` is set to the minimum depth required by your deepest legitimate query
5. [ ] `max-conditions` is set to a realistic upper bound for legitimate use cases
6. [ ] The `regexp` operator is disabled if not needed by any client
7. [ ] JPA entities are never returned directly from controller methods — always DTOs or Views
8. [ ] `@FieldsByRole` is applied to any entity with fields that differ by user role
9. [ ] Multi-tenancy filtering is applied at the `count`/`fetchIds`/`fetchEntities` level
10. [ ] `hibernate.query.fail_on_pagination_over_collection_fetch=true` is set in all environments
11. [ ] Spring Security `@PreAuthorize` is applied to all write endpoints (POST, PUT, DELETE)
12. [ ] Response DTOs do not include internal fields (`tenantId`, `version`, `passwordHash`, `auditLog`)
13. [ ] Bulk endpoints enforce the same `@PreAuthorize` restrictions as their single-entity equivalents
14. [ ] `max-page-size` is configured to prevent accidental full-table downloads via large `pageSize` values
