# rest-generic-class — Entity Annotations and the Allowlist Model

## Overview

The allowlist model is the library's primary security mechanism. Before any filter condition, relation load, or order-by clause reaches the database, it must be explicitly declared on the entity class via an annotation. Undeclared paths are rejected with HTTP 400 before any SQL is constructed.

There are four annotations in `com.ronu.restgeneric.annotation`:

| Annotation         | Purpose                                                          |
|--------------------|------------------------------------------------------------------|
| `@AllowedRelations` | Declares which relation paths may be loaded and filtered on      |
| `@AllowedOrderBy`  | Declares which field paths may appear in `orderby` clauses       |
| `@FieldsByRole`    | Declares which fields are accessible per Spring Security role    |
| `@CacheInvalidates` | Lists entity classes whose cache is invalidated on writes        |

---

## `@AllowedRelations`

### Declaration

```java
package com.ronu.restgeneric.annotation;

import java.lang.annotation.*;

@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
@Documented
public @interface AllowedRelations {
    String[] value();
}
```

### Usage

```java
@Entity
@AllowedRelations({"department", "roles", "manager.department"})
public class UserEntity { ... }
```

### What It Controls

`@AllowedRelations` governs two independent usages of the relation path:

**1. Eager loading (`relations` field in the request body)**

When a caller sends `"relations": ["department"]`, the service will include `department` in the entity graph. If `department` is not in `@AllowedRelations`, `PathValidator.validateRelationPath()` throws `InvalidRelationException` → HTTP 400.

**2. Relation-scoped filter (`oper` keys other than `and`/`or`)**

When a caller sends `"oper": {"department": {"and": ["active|=|true"]}}`, the key `department` is a relation filter (equivalent to `whereHas()`). The same allowlist check applies before the correlated subquery is constructed.

### Dot-Notation for Nested Relations

The value `"manager.department"` in `@AllowedRelations` declares that the caller may traverse `UserEntity → manager → department`. The dot-notation path is checked as a unit against the allowlist using `AllowlistRegistry.isRelationAllowed(entityClass, path)`.

Additionally, `isRelationRootAllowed(entityClass, path)` checks whether just the root segment of a path is allowed. For example, if `"manager"` is in the allowlist but `"manager.department"` is not, `isRelationRootAllowed` returns `true` for `"manager.department"`. This is used internally when validating deeply nested eager-load paths.

### Security Implications

Without `@AllowedRelations`:
- All relation loading and relation-scoped filters are blocked (`AllowlistRegistry` returns an empty set → `PathValidator` throws for any path).

With a misconfigured allowlist (e.g., wildcard or overly broad entries):
- A caller could force loading of sensitive relations (e.g., `user.auditLogs`, `user.payments`) that were not intended to be accessible via this endpoint.

**There is no wildcard support.** Every path must be listed explicitly. This is a deliberate design decision — explicit is safer and easier to audit.

### Difference Between `isRelationAllowed` and `isRelationRootAllowed`

```java
// Exact match — used by PathValidator.validateRelationPath()
public boolean isRelationAllowed(Class<?> entityClass, String path) {
    return getAllowedRelations(entityClass).contains(path);
}

// Root segment match — used internally for partial path checks
public boolean isRelationRootAllowed(Class<?> entityClass, String path) {
    String root = path.contains(".") ? path.substring(0, path.indexOf('.')) : path;
    return isRelationAllowed(entityClass, path) || isRelationAllowed(entityClass, root);
}
```

---

## `@AllowedOrderBy`

### Declaration

```java
package com.ronu.restgeneric.annotation;

import java.lang.annotation.*;

@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
@Documented
public @interface AllowedOrderBy {
    String[] value() default {};
}
```

### Usage

```java
@Entity
@AllowedOrderBy({"name", "createdAt", "department.name"})
public class UserEntity { ... }
```

### What It Controls

Every path in the `orderby` request field is validated against this list via `AllowlistRegistry.isOrderByAllowed()` and `PathValidator.validateOrderByPath()`.

### Special Case: Annotation Absent vs. Empty

The annotation has `default {}` (empty array). This means:

| State                                | Behaviour                                      |
|--------------------------------------|------------------------------------------------|
| Annotation absent from entity        | Only local (non-dot) fields are sortable       |
| `@AllowedOrderBy({})` explicitly     | Same as absent — only local fields              |
| `@AllowedOrderBy({"name", "..."})`   | Only the listed paths are sortable             |

The safe default — when `@AllowedOrderBy` is absent — allows sorting by any local scalar field but blocks relational sorting. This prevents unintended cross-join sorts.

`AllowlistRegistry.isOrderByAllowed()` implements this logic:

```java
public boolean isOrderByAllowed(Class<?> entityClass, String path) {
    Set<String> allowed = getAllowedOrderBy(entityClass);
    if (allowed.isEmpty()) {
        // No annotation present — only allow local fields (no dot notation)
        return !path.contains(".");
    }
    return allowed.contains(path);
}
```

### Cross-Relation Ordering and `OrderByCompiler`

When an `orderby` path contains a dot (e.g., `"department.name"`), `OrderByCompiler` uses `PathValidator.isToManyPath()` to determine the relation cardinality:

- **To-one path** (`department.name` where `department` is `@ManyToOne`) → Compiled into a Spring Data `Sort` object. The join is resolved directly.
- **To-many path** (`roles.name` where `roles` is `@ManyToMany`) → Compiled into a correlated scalar subquery injected into the `CriteriaQuery.orderBy()` list. This avoids duplicate rows from a collection join.

---

## `@FieldsByRole`

### Declaration

```java
package com.ronu.restgeneric.annotation;

import java.lang.annotation.*;

@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
@Documented
public @interface FieldsByRole {

    RoleFields[] value();

    @interface RoleFields {
        String role();
        String[] fields();
    }
}
```

### Usage

```java
@Entity
@FieldsByRole({
    @FieldsByRole.RoleFields(role = "ROLE_ADMIN",   fields = {"salary", "internalCode", "socialSecurityNumber"}),
    @FieldsByRole.RoleFields(role = "ROLE_MANAGER", fields = {"internalCode"})
})
public class EmployeeEntity { ... }
```

### Integration with Spring Security

`@FieldsByRole` declares which fields are accessible for each Spring Security authority string. The integration with Spring Security's `Authentication` object is performed by your application — typically in a custom `toView()` implementation or a view projection filter that reads the current principal's authorities and masks fields accordingly.

**Example pattern:**

```java
@Override
protected EmployeeDto toView(EmployeeEntity e) {
    Authentication auth = SecurityContextHolder.getContext().getAuthentication();
    boolean isAdmin = auth.getAuthorities().stream()
        .anyMatch(a -> a.getAuthority().equals("ROLE_ADMIN"));

    return new EmployeeDto(
        e.getId(),
        e.getName(),
        isAdmin ? e.getSalary() : null,          // masked for non-admin
        isAdmin ? e.getInternalCode() : null     // masked for non-admin
    );
}
```

The annotation itself is retained at runtime and can be read programmatically to build a generic field masking layer without hardcoding role checks in every `toView()` method.

---

## `@CacheInvalidates`

### Declaration

```java
package com.ronu.restgeneric.annotation;

import java.lang.annotation.*;

@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
@Documented
public @interface CacheInvalidates {
    Class<?>[] value();
}
```

### Usage

```java
@Entity
@CacheInvalidates({DepartmentEntity.class, RoleEntity.class})
public class UserEntity { ... }
```

### Semantics

When `UserEntity` is written (created, updated, or deleted via `BaseCrudService`), the cache entries for `DepartmentEntity` and `RoleEntity` should also be evicted. This is the Java equivalent of PHP's `const CACHE_INVALIDATES = [...]` pattern.

The annotation value uses `Class<?>[]` (not `String[]`) so that refactoring the entity class name is caught at compile time.

**Important:** The `@CacheInvalidates` annotation is a **declaration**, not an automatic mechanism. Your application must read it and trigger the evictions. Example:

```java
@Transactional
@Override
public V create(Map<String, Object> data) {
    V result = super.create(data);
    evictRelatedCaches(entityClass()); // reads @CacheInvalidates and evicts
    return result;
}

private void evictRelatedCaches(Class<?> entityClass) {
    CacheInvalidates ci = entityClass.getAnnotation(CacheInvalidates.class);
    if (ci != null) {
        for (Class<?> target : ci.value()) {
            cacheManager.getCache(target.getSimpleName()).clear();
        }
    }
}
```

---

## `AllowlistRegistry` Programmatic API

`AllowlistRegistry` is a Spring-managed `@Component` (auto-configured via `RestGenericAutoConfiguration`). It is a thread-safe in-process registry backed by `ConcurrentHashMap`.

### Methods

#### `register(Class<?> entityClass)`

Reads `@AllowedRelations` and `@AllowedOrderBy` from the given class and stores the allowed sets. Called automatically by `QueryPlanCompiler.compile()` on first use of an entity.

```java
allowlistRegistry.register(UserEntity.class);
```

#### `registerRelations(Class<?> entityClass, Set<String> relations)`

Programmatically overrides the allowed relation set for an entity class. Useful in tests or for dynamic configuration loaded from a database.

```java
allowlistRegistry.registerRelations(UserEntity.class, Set.of("department", "roles", "manager"));
```

#### `registerOrderBy(Class<?> entityClass, Set<String> paths)`

Programmatically overrides the allowed orderby paths for an entity class.

```java
allowlistRegistry.registerOrderBy(UserEntity.class, Set.of("name", "email", "department.name"));
```

#### `isRelationAllowed(Class<?> entityClass, String path)`

Returns `true` if the exact path is in the entity's allowed relation set.

```java
boolean ok = allowlistRegistry.isRelationAllowed(UserEntity.class, "department"); // true
boolean ok2 = allowlistRegistry.isRelationAllowed(UserEntity.class, "payments");  // false → 400
```

#### `isOrderByAllowed(Class<?> entityClass, String path)`

Returns `true` if the path is in the entity's allowed orderby set, or if no annotation is present and the path is a local (non-dot) field.

```java
boolean ok = allowlistRegistry.isOrderByAllowed(UserEntity.class, "name");           // true
boolean ok2 = allowlistRegistry.isOrderByAllowed(UserEntity.class, "salary");        // depends on annotation
boolean ok3 = allowlistRegistry.isOrderByAllowed(UserEntity.class, "payments.amount"); // false if not listed
```

#### `getAllowedRelations(Class<?> entityClass)` / `getAllowedOrderBy(Class<?> entityClass)`

Return the full `Set<String>` for a class. Return an empty set if the class has not been registered.

---

## `PathValidator` and the JPA Metamodel

`PathValidator` has two responsibilities:
1. Check the allowlist (delegating to `AllowlistRegistry`).
2. Walk the JPA `Metamodel` to determine relation cardinality (to-one vs. to-many).

The metamodel walk is performed by `isToManyPath(Class<?> rootEntity, String[] segments)`:

```java
public boolean isToManyPath(Class<?> rootEntity, String[] segments) {
    Metamodel metamodel = emf.getMetamodel();
    ManagedType<?> current = metamodel.managedType(rootEntity);

    for (String segment : segments) {
        Attribute<?, ?> attr = getAttribute(current, segment);
        if (attr == null) return false; // unknown — treat as to-one

        if (attr instanceof PluralAttribute) {
            return true; // to-many found at or before leaf
        }
        if (attr instanceof SingularAttribute<?, ?> singular) {
            Class<?> type = singular.getType().getJavaType();
            try {
                current = metamodel.managedType(type);
            } catch (IllegalArgumentException e) {
                return false; // leaf is a basic type (String, Long, etc.)
            }
        }
    }
    return false;
}
```

### Deep Example: `UserEntity → roles` (ManyToMany = PluralAttribute → `isToMany=true`)

Given `UserEntity` with:
```java
@ManyToMany
private List<RoleEntity> roles = new ArrayList<>();
```

Calling `isToManyPath(UserEntity.class, new String[]{"roles"})`:
1. `metamodel.managedType(UserEntity.class)` → `EntityType<UserEntity>`
2. `getAttribute(userEntityType, "roles")` → returns the `PluralAttribute` for `List<RoleEntity>`
3. `attr instanceof PluralAttribute` → `true`
4. Return `true` immediately

Result: `roles` is to-many → `TwoPhaseDetector` returns `true` → `searchTwoPhase()` is used when pagination is requested.

### Deep Example: `UserEntity → department` (ManyToOne = SingularAttribute → `isToMany=false`)

Given `UserEntity` with:
```java
@ManyToOne(fetch = FetchType.LAZY)
@JoinColumn(name = "department_id")
private DepartmentEntity department;
```

Calling `isToManyPath(UserEntity.class, new String[]{"department"})`:
1. `metamodel.managedType(UserEntity.class)` → `EntityType<UserEntity>`
2. `getAttribute(userEntityType, "department")` → returns the `SingularAttribute` for `DepartmentEntity`
3. `attr instanceof PluralAttribute` → `false`
4. `attr instanceof SingularAttribute` → `true`
5. `singular.getType().getJavaType()` → `DepartmentEntity.class`
6. `metamodel.managedType(DepartmentEntity.class)` → succeeds → `current = EntityType<DepartmentEntity>`
7. Loop ends (no more segments)
8. Return `false`

Result: `department` is to-one → single-phase query is safe → `TwoPhaseDetector` returns `false`.

### Deep Example: Cross-relation path `department.name`

Calling `isToManyPath(UserEntity.class, new String[]{"department", "name"})`:
1. Walk to `department` → `SingularAttribute` → advance to `EntityType<DepartmentEntity>`
2. Walk to `name` on `DepartmentEntity` → `SingularAttribute<DepartmentEntity, String>`
3. `singular.getType().getJavaType()` → `String.class`
4. `metamodel.managedType(String.class)` → throws `IllegalArgumentException` (String is not a managed type)
5. Catch exception → return `false`

Result: `department.name` is a scalar field reached via a to-one join → `OrderByCompiler` puts it in the `Sort` object (not a subquery).

---

## Complete Annotated Entity Example

The following entity uses all four annotations:

```java
import com.ronu.restgeneric.annotation.*;
import jakarta.persistence.*;
import java.math.BigDecimal;
import java.time.LocalDateTime;
import java.util.ArrayList;
import java.util.List;

@Entity
@Table(name = "employees")
@AllowedRelations({"department", "roles", "manager", "manager.department"})
@AllowedOrderBy({"name", "email", "createdAt", "salary", "department.name", "manager.name"})
@FieldsByRole({
    @FieldsByRole.RoleFields(role = "ROLE_ADMIN",   fields = {"salary", "internalCode", "socialSecurityNumber"}),
    @FieldsByRole.RoleFields(role = "ROLE_MANAGER", fields = {"internalCode"})
})
@CacheInvalidates({DepartmentEntity.class, RoleEntity.class})
public class EmployeeEntity {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    private String name;
    private String email;
    private String status;

    // Sensitive fields — visible only to specific roles via @FieldsByRole
    private BigDecimal salary;
    private String internalCode;
    private String socialSecurityNumber;

    private LocalDateTime createdAt;
    private LocalDateTime updatedAt;

    // To-one: department (safe for single-phase pagination)
    @ManyToOne(fetch = FetchType.LAZY)
    @JoinColumn(name = "department_id")
    private DepartmentEntity department;

    // To-one: manager (another employee)
    @ManyToOne(fetch = FetchType.LAZY)
    @JoinColumn(name = "manager_id")
    private EmployeeEntity manager;

    // To-many: roles (triggers two-phase pagination when included)
    @ManyToMany
    @JoinTable(
        name = "employee_roles",
        joinColumns = @JoinColumn(name = "employee_id"),
        inverseJoinColumns = @JoinColumn(name = "role_id")
    )
    private List<RoleEntity> roles = new ArrayList<>();

    public EmployeeEntity() {}

    // getters and setters omitted for brevity
}
```

### Annotation Explanations for This Entity

- `@AllowedRelations({"department", "roles", "manager", "manager.department"})` — callers may load the employee's department, roles, manager, and the manager's department. They may also use any of these as relation-scoped filters in `oper`.
- `@AllowedOrderBy({"name", "email", "createdAt", "salary", "department.name", "manager.name"})` — callers may sort by these fields. Note that `department.name` and `manager.name` are cross-relation paths that require the corresponding relation to be joinable.
- `@FieldsByRole(...)` — declares that `salary`, `internalCode`, and `socialSecurityNumber` are admin-only; `internalCode` is also visible to managers. The actual masking is performed in your `toView()` implementation.
- `@CacheInvalidates({DepartmentEntity.class, RoleEntity.class})` — when an employee is written, the cache for `DepartmentEntity` and `RoleEntity` should be invalidated (because employees affect department headcount, role membership, etc.).
