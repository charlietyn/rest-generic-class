# 13. Blaze-Persistence Integration

## What Is Blaze-Persistence and Why Use It?

Blaze-Persistence is a library that extends JPA/Hibernate with a richer Criteria API and, critically, **Entity Views** — a projection mechanism that lets you load exactly the columns and relations your use case needs, with no N+1 queries and no over-fetching.

### Core Benefits of Entity Views

| Benefit | Detail |
|---|---|
| No N+1 on collections | `List<RoleView> getRoles()` is fetched in a single query using SUBSELECT or MULTISET, not a query per entity |
| No over-fetching | Only the columns declared in the view interface are included in the SQL SELECT |
| Nested subviews | `DepartmentView getDepartment()` becomes a LEFT JOIN — one query, no separate lookup |
| Top-N per collection | `@Limit(5)` on a collection attribute loads only the most recent N items |
| Type-safe projections | Interface method names are validated against the entity metamodel at startup |
| Write support | Entity Views support `@UpdatableEntityView` for partial updates through the same interface |

### When to Use Blaze vs Plain DTO vs MapStruct

| Scenario | Recommendation |
|---|---|
| Simple flat projections | Record DTO + `toView()` in service |
| Nested relations without N+1 | Blaze-Persistence Entity Views |
| Complex business mapping logic | MapStruct |
| Performance-critical API with nested data | Blaze Entity Views + MULTISET fetch |
| Quick prototyping or internal tooling | Record DTO |

---

## The javax/jakarta Challenge

This is the most important technical constraint to understand before adding Blaze-Persistence to a Spring Boot 3 project.

### The Root Problem

Blaze-Persistence 1.6.x was written against the `javax.persistence` namespace. Spring Boot 3 migrated to Jakarta EE 10, which uses the `jakarta.persistence` namespace. At the JVM level, `javax.persistence.EntityManager` and `jakarta.persistence.EntityManager` are **completely different types** — they share no common supertype and cannot be used interchangeably.

The `CriteriaBuilderFactory` public API in Blaze 1.6.x declares its `create()` method as:

```java
// Blaze 1.6.x CriteriaBuilderFactory
public CriteriaBuilder<T> create(javax.persistence.EntityManager em, Class<T> clazz);
```

Spring Boot 3 injects `jakarta.persistence.EntityManager`. Passing a Jakarta `EntityManager` directly to `cbf.create()` produces a compile-time type error:

```java
// THIS FAILS with Spring Boot 3:
@Autowired EntityManager em;  // jakarta.persistence.EntityManager

// ERROR: incompatible types — jakarta.persistence.EntityManager cannot be passed where
// javax.persistence.EntityManager is expected
CriteriaBuilder<UserEntity> cb = cbf.create(em, UserEntity.class);
```

### The Solution: Never Call cbf.create() Directly

The Blaze-Persistence Spring Data integration module handles the `javax`/`jakarta` bridge **internally**, using reflection and class-loader tricks to resolve the conflict at runtime. You must use the Spring Data repository bridge — **never** call `cbf.create(em, ...)` directly in application code.

```java
// CORRECT: Spring Data integration handles javax/jakarta internally
@Repository
public interface UserRepository
    extends JpaRepository<UserEntity, Long>,
            JpaSpecificationExecutor<UserEntity>,
            EntityViewSpecificationExecutor<UserView, UserEntity> {}
```

When you call `userRepository.findAll(spec, EntityViewSetting.create(UserView.class), pageable)`, the integration module resolves the EntityManager type conflict internally before delegating to Blaze-Persistence's core.

---

## Adding Blaze-Persistence to Your Project

Add the following dependencies to your `pom.xml`. The `runtime`-scoped artifacts are implementation modules — they are not needed at compile time but must be on the classpath at runtime.

```xml
<dependencies>
  <!-- Core Criteria API -->
  <dependency>
    <groupId>com.blazebit</groupId>
    <artifactId>blaze-persistence-core-api</artifactId>
    <version>1.6.12</version>
  </dependency>
  <dependency>
    <groupId>com.blazebit</groupId>
    <artifactId>blaze-persistence-core-impl</artifactId>
    <version>1.6.12</version>
    <scope>runtime</scope>
  </dependency>

  <!-- Entity Views -->
  <dependency>
    <groupId>com.blazebit</groupId>
    <artifactId>blaze-persistence-entity-view-api</artifactId>
    <version>1.6.12</version>
  </dependency>
  <dependency>
    <groupId>com.blazebit</groupId>
    <artifactId>blaze-persistence-entity-view-impl</artifactId>
    <version>1.6.12</version>
    <scope>runtime</scope>
  </dependency>

  <!-- Spring Data 3.3 integration (Spring Boot 3 compatible) -->
  <dependency>
    <groupId>com.blazebit</groupId>
    <artifactId>blaze-persistence-integration-spring-data-3.3</artifactId>
    <version>1.6.12</version>
  </dependency>

  <!-- Hibernate 6 integration (runtime only) -->
  <dependency>
    <groupId>com.blazebit</groupId>
    <artifactId>blaze-persistence-integration-hibernate-6</artifactId>
    <version>1.6.12</version>
    <scope>runtime</scope>
  </dependency>
</dependencies>
```

**Important version notes:**

- `blaze-persistence-integration-spring-data-3.3` corresponds to Spring Data 3.3.x, which ships with Spring Boot 3.3. Do not use `spring-data-2.x` or `spring-data-3.1` variants with Spring Boot 3.3.
- `blaze-persistence-integration-hibernate-6` is required for Hibernate 6.x. Do not use `hibernate-5.x` variants.
- All artifacts should be on the same Blaze-Persistence version (1.6.12 in this example).

---

## Configuring CriteriaBuilderFactory and EntityViewManager

Blaze-Persistence requires two Spring beans. Both must be eager singletons — `@Lazy(false)` ensures they are initialised at application startup rather than on first use, which catches configuration errors early.

```java
@Configuration
public class BlazeConfig {

    @PersistenceUnit
    private EntityManagerFactory emf;

    @Bean
    @Scope(ConfigurableBeanFactory.SCOPE_SINGLETON)
    @Lazy(false)
    public CriteriaBuilderFactory criteriaBuilderFactory() {
        CriteriaBuilderConfiguration config = Criteria.getDefault();
        // Optional: configure properties
        // config.setProperty("com.blazebit.persistence.expression.cache_size", "1000");
        return config.createCriteriaBuilderFactory(emf);
    }

    @Bean
    @Scope(ConfigurableBeanFactory.SCOPE_SINGLETON)
    @Lazy(false)
    public EntityViewManager entityViewManager(CriteriaBuilderFactory cbf) {
        EntityViewConfiguration cfg = EntityViews.createDefaultConfiguration();

        // Register all your entity view interfaces
        cfg.addEntityView(UserView.class);
        cfg.addEntityView(DepartmentView.class);
        cfg.addEntityView(RoleView.class);

        return cfg.createEntityViewManager(cbf);
    }
}
```

Alternatively, use Blaze-Persistence's `@EnableBlazeRepositories` annotation to enable auto-scanning of entity views:

```java
@Configuration
@EnableBlazeRepositories(basePackages = "com.example.repository")
public class BlazeConfig {
    // Spring Boot auto-configuration handles CriteriaBuilderFactory and EntityViewManager
    // if blaze-persistence-integration-spring-boot-3 is on the classpath
}
```

---

## Defining Entity Views

Entity Views are Java interfaces annotated with `@EntityView`. Each getter method maps to a field, association, or expression on the entity.

```java
@EntityView(UserEntity.class)
public interface UserView {
    Long getId();
    String getName();
    String getEmail();
    String getStatus();

    // Nested subview — becomes a LEFT JOIN in the main query (one SQL query total)
    @AttributeFilter(EqualFilter.class)
    DepartmentView getDepartment();

    // Collection subview — fetched via SUBSELECT by default (one additional query)
    List<RoleView> getRoles();

    // Computed expression — evaluated in SQL
    @MappingExpression("CONCAT(firstName, ' ', lastName)")
    String getFullName();
}

@EntityView(DepartmentEntity.class)
public interface DepartmentView {
    Long getId();
    String getName();
    Boolean getActive();
}

@EntityView(RoleEntity.class)
public interface RoleView {
    Long getId();
    String getName();
}
```

**Naming convention:** By default, `getName()` maps to the `name` field on the entity. `getDepartment()` maps to the `department` association. If your getter name does not match the field, use `@Mapping("actualFieldName")`.

---

## Entity View Fetch Strategies

Blaze-Persistence supports four fetch strategies for associations. The right choice depends on the data shape and query volume.

| Strategy | SQL Pattern | Best For |
|---|---|---|
| JOIN (default for to-one) | LEFT JOIN in the main query | `@ManyToOne`, `@OneToOne` — never multiplies rows |
| SELECT | Separate `SELECT` per parent entity | Small collections, rarely needed |
| SUBSELECT | One `SELECT` with `WHERE parentId IN (...)` per batch | Large collections — the recommended default |
| MULTISET | Nested result set (DB-specific) | Complex deeply nested views, maximum efficiency |

Override the fetch strategy per attribute:

```java
@EntityView(UserEntity.class)
public interface UserView {
    // Explicit SUBSELECT for the roles collection
    @Fetch(FetchStrategy.SUBSELECT)
    List<RoleView> getRoles();

    // MULTISET for a complex nested structure
    @Fetch(FetchStrategy.MULTISET)
    List<OrderView> getRecentOrders();
}
```

---

## @Limit for Top-N Collections

Load only the most recent or most relevant N items from a collection, entirely in SQL:

```java
@EntityView(UserEntity.class)
public interface UserView {
    // Load only the 3 most recently assigned roles
    @Limit(value = 3, order = "assignedAt DESC")
    List<RoleView> getRoles();

    // Load the 5 most recent orders
    @Limit(value = 5, order = "createdAt DESC")
    List<OrderSummaryView> getRecentOrders();
}
```

`@Limit` is implemented as a window function or subquery depending on the database, and runs entirely in the database engine — no application-level slicing.

---

## Repository with EntityViewSpecificationExecutor

Extend your Spring Data repository with `EntityViewSpecificationExecutor<ViewType, EntityType>`:

```java
@Repository
public interface UserRepository
    extends JpaRepository<UserEntity, Long>,
            JpaSpecificationExecutor<UserEntity>,
            EntityViewSpecificationExecutor<UserView, UserEntity> {
}
```

`EntityViewSpecificationExecutor` adds overloads of `findAll` that accept both a `Specification` (for filtering) and an `EntityViewSetting` (for projection):

```java
// Returns a Page<UserView> — filtered by spec, projected to UserView
Page<UserView> page = userRepository.findAll(
    spec,
    EntityViewSetting.create(UserView.class),
    PageRequest.of(0, 20)
);
```

---

## Integrating with rest-generic-class

Override `searchSinglePhase()` and `searchTwoPhase()` in your service subclass to route Phase 2 loading through Blaze-Persistence Entity Views.

```java
@Service
public class UserService extends BaseCrudService<UserEntity, UserView, Long> {

    private final UserRepository userRepository;
    private final FilterCompiler filterCompiler;
    private final OrderByCompiler orderByCompiler;

    public UserService(EntityManager em, QueryPlanCompiler compiler,
                       FilterCompiler filterCompiler, OrderByCompiler orderByCompiler,
                       UserRepository userRepository) {
        super(em, compiler, filterCompiler, orderByCompiler);
        this.filterCompiler = filterCompiler;
        this.orderByCompiler = orderByCompiler;
        this.userRepository = userRepository;
    }

    @Override
    protected Class<UserEntity> entityClass() {
        return UserEntity.class;
    }

    @Override
    protected UserView toView(UserEntity e) {
        // toView() is called by the base class for create/update operations,
        // which go through mapData() → save() → toView().
        // For search paths we override searchSinglePhase/searchTwoPhase instead.
        throw new UnsupportedOperationException(
            "Direct entity→view conversion not supported; use searchSinglePhase override");
    }

    @Override
    protected Page<UserView> searchSinglePhase(QueryPlan plan) {
        Specification<UserEntity> spec = filterCompiler.toSpecification(plan.filterTree());
        Pageable pageable = plan.pagination().toPageable();
        // EntityViewSpecificationExecutor handles the javax/jakarta bridge internally
        return userRepository.findAll(spec, EntityViewSetting.create(UserView.class), pageable);
    }

    @Override
    protected Page<UserView> searchTwoPhase(QueryPlan plan) {
        // Phase 1: resolve page of IDs using the parent class scalar query
        Specification<UserEntity> spec = filterCompiler.toSpecification(plan.filterTree());
        OrderByCompiler.Result<UserEntity> orderResult = orderByCompiler.compile(
            plan.orderItems(), entityClass(), plan.relationIsToMany());

        List<Object> ids = fetchIds(spec, orderResult, plan.pagination());

        if (ids.isEmpty()) {
            return new PageImpl<>(List.of(), plan.pagination().toPageable(), 0L);
        }

        // Phase 2: load Entity Views by IDs — no LIMIT, no row multiplication risk
        Specification<UserEntity> idSpec = (root, q, cb) -> root.get("id").in(ids);
        Page<UserView> views = userRepository.findAll(
            idSpec,
            EntityViewSetting.create(UserView.class),
            Pageable.unpaged()  // no LIMIT on Phase 2
        );

        long total = count(spec);
        return new PageImpl<>(views.getContent(), plan.pagination().toPageable(), total);
    }
}
```

**Key points in the integration:**

1. `filterCompiler.toSpecification()` converts the parsed filter AST to a JPA `Specification` — the same `Specification` type that `JpaSpecificationExecutor` and `EntityViewSpecificationExecutor` accept.
2. Phase 1 (`fetchIds`) is inherited from `BaseCrudService` unchanged — it selects scalar IDs, not entities.
3. Phase 2 passes `Pageable.unpaged()` to prevent the `IN` query from having a spurious `LIMIT`. The page boundary was already established by the ID list.
4. `toView()` is only needed for create/update operations. For search, the overridden methods bypass it entirely.

---

## Filtering Entity Views with @AttributeFilter

Entity Views can declare supported filter attributes, enabling Blaze-Persistence to generate optimised filter predicates:

```java
@EntityView(UserEntity.class)
@EntityViewFilter(name = "activeOnly",
                  value = AttributeFilterProvider.class,
                  forAttribute = "status")
public interface UserView {
    @AttributeFilter(EqualFilter.class)
    String getStatus();

    @AttributeFilter(ContainsFilter.class)
    String getName();
}
```

This integrates with `EntityViewSetting`:

```java
EntityViewSetting<UserView, ?> setting = EntityViewSetting.create(UserView.class);
setting.addAttributeFilter("status", "ACTIVE");
setting.addAttributeFilter("name", "John");
Page<UserView> results = userRepository.findAll(baseSpec, setting, pageable);
```

For most use cases with `rest-generic-class`, the `Specification`-based filtering from `FilterCompiler` is sufficient and does not require `@AttributeFilter`. Use `@AttributeFilter` when you want Blaze-Persistence to generate the predicates rather than the library's `FilterCompiler`.

---

## Complete Working Example: User List with Roles and Department

**Entity:**

```java
@Entity
@Table(name = "users")
@AllowedRelations({"department", "roles"})
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

**Entity Views:**

```java
@EntityView(UserEntity.class)
public interface UserView {
    Long getId();
    String getName();
    String getEmail();
    String getStatus();
    DepartmentView getDepartment();

    @Fetch(FetchStrategy.SUBSELECT)
    List<RoleView> getRoles();
}

@EntityView(DepartmentEntity.class)
public interface DepartmentView {
    Long getId();
    String getName();
}

@EntityView(RoleEntity.class)
public interface RoleView {
    Long getId();
    String getName();
}
```

**POST /search request:**

```json
{
  "oper": {
    "and": ["status|=|ACTIVE"],
    "department": {"and": ["active|=|true"]}
  },
  "relations": ["department", "roles"],
  "orderby": [{"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 25}
}
```

**Queries generated by Blaze-Persistence:**

```sql
-- Main entity query (users + department via JOIN)
SELECT u.id, u.name, u.email, u.status, d.id, d.name
FROM users u
LEFT JOIN departments d ON u.department_id = d.id
WHERE u.status = 'ACTIVE'
  AND d.active = true
ORDER BY u.name ASC
LIMIT 25 OFFSET 0

-- Roles SUBSELECT (one query for all loaded users)
SELECT ur.user_id, r.id, r.name
FROM user_roles ur
JOIN roles r ON ur.role_id = r.id
WHERE ur.user_id IN (1, 2, 3, 4, 5, ...)
```

Without Blaze-Persistence Entity Views, loading roles would require either a `JOIN FETCH` (causing row multiplication + pagination problems) or N separate queries per user.
