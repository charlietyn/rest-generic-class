# Chapter 9: Building Services

Services are the core of a `rest-generic-class` application. The abstract base classes `BaseQueryService<E, V>` and `BaseCrudService<E, V, ID>` provide the full query and mutation pipeline, leaving two mandatory abstract methods and several optional extension points for concrete implementations.

---

## `BaseQueryService<E, V>` Contract

`BaseQueryService` owns the full search pipeline: it receives a compiled `QueryPlan`, runs single-phase or two-phase execution, and delegates entity-to-view mapping to the subclass.

```java
public abstract class BaseQueryService<E, V> {

    protected final EntityManager em;
    protected final QueryPlanCompiler compiler;
    protected final FilterCompiler filterCompiler;
    protected final OrderByCompiler orderByCompiler;

    protected BaseQueryService(EntityManager em, QueryPlanCompiler compiler,
                                FilterCompiler filterCompiler, OrderByCompiler orderByCompiler) {
        this.em = em;
        this.compiler = compiler;
        this.filterCompiler = filterCompiler;
        this.orderByCompiler = orderByCompiler;
    }

    // Mandatory — subclass must implement both
    protected abstract Class<E> entityClass();
    protected abstract V toView(E entity);

    // Optional overrides — safe to call super or replace entirely
    protected Page<V> searchSinglePhase(QueryPlan plan) { ... }
    protected Page<V> searchTwoPhase(QueryPlan plan) { ... }
    protected long count(Specification<E> spec) { ... }
    protected List<Object> fetchIds(Specification<E> spec,
                                    OrderByCompiler.Result<E> orderResult,
                                    Pagination pg) { ... }
    protected List<E> fetchEntities(Specification<E> spec,
                                    OrderByCompiler.Result<E> orderResult,
                                    Pagination pg) { ... }
}
```

All injected collaborators are Spring-managed beans. The `EntityManager` injected here is a Spring transaction-scoped proxy — it is thread-safe at the proxy level and delegates each call to the EntityManager bound to the current transaction.

---

## The Two Mandatory Abstract Methods

### `entityClass()`

Returns the JPA entity `Class<E>`. Used internally for:

- `em.find(entityClass(), id)` — single-entity lookup
- `em.createEntityGraph(entityClass())` — dynamic EntityGraph construction
- `em.getCriteriaBuilder()` + `cb.createQuery(entityClass())` — criteria query root type

```java
@Override
protected Class<UserEntity> entityClass() {
    return UserEntity.class;
}
```

### `toView(E entity)`

Maps a loaded JPA entity to the view/DTO type `V`. Called on every entity in every result: search pages, single lookups, after creates, after updates.

```java
@Override
protected UserDto toView(UserEntity entity) {
    return new UserDto(entity.getId(), entity.getName(), entity.getEmail());
}
```

`toView()` is called while a Hibernate session is open (inside the transaction), so accessing LAZY associations is safe as long as those associations are loaded — either by EntityGraph or by the Hibernate batch-fetch mechanism. Accessing an unloaded LAZY proxy outside a transaction throws `LazyInitializationException`.

---

## Three `toView()` Implementation Strategies

### Strategy 1 — Manual DTO Constructor (Simplest)

Construct the DTO inline using getters. No additional dependencies.

```java
@Override
protected UserDto toView(UserEntity entity) {
    return new UserDto(
        entity.getId(),
        entity.getName(),
        entity.getEmail(),
        entity.getStatus(),
        entity.getDepartment() != null ? entity.getDepartment().getName() : null
    );
}
```

**Caution:** accessing `entity.getDepartment().getName()` triggers a lazy load if `department` was not included in the EntityGraph. In a search context this causes one extra SQL query per result row — the classic N+1 pattern. Only access nested associations in `toView()` if you are certain they are loaded.

### Strategy 2 — MapStruct (Recommended for Complex Mappings)

MapStruct generates type-safe, null-safe mapping code at compile time. Declare a `@Mapper` interface, inject it into the service, and delegate.

```java
@Mapper(componentModel = "spring")
public interface UserMapper {
    @Mapping(source = "department.name", target = "departmentName")
    UserDto toDto(UserEntity entity);
}

@Service
public class UserService extends BaseCrudService<UserEntity, UserDto, Long> {

    private final UserMapper mapper;

    @Autowired
    public UserService(EntityManager em, QueryPlanCompiler compiler,
                       FilterCompiler fc, OrderByCompiler oc, UserMapper mapper) {
        super(em, compiler, fc, oc);
        this.mapper = mapper;
    }

    @Override
    protected Class<UserEntity> entityClass() { return UserEntity.class; }

    @Override
    protected UserDto toView(UserEntity entity) {
        return mapper.toDto(entity);
    }
}
```

MapStruct resolves `department.name` to `entity.getDepartment().getName()` with a null-safety check, which still triggers a lazy load if `department` is not in-session. Use MapStruct's `@BeanMapping(ignoreByDefault = true)` or conditional mappings to avoid accessing unloaded associations.

### Strategy 3 — Blaze-Persistence EntityViewManager (See Chapter 13)

Blaze-Persistence Entity Views push the projection down into the SQL query itself. The simplest adapter calls `evm.find()` per entity:

```java
@Override
protected UserView toView(UserEntity entity) {
    return evm.find(em, UserView.class, entity.getId());
}
```

This fires one extra query per entity — worse than MapStruct. The correct Blaze-Persistence approach is to override `searchSinglePhase()` to use `EntityViewSpecificationExecutor`, bypassing `toView()` entirely and loading optimized projections in one query. Chapter 13 covers this pattern in detail.

---

## `BaseCrudService<E, V, ID>` Additions

`BaseCrudService` extends `BaseQueryService` with transactional write operations. All methods use `@Transactional` with Spring's default propagation (`REQUIRED`) — they join an existing transaction or create a new one.

```java
public abstract class BaseCrudService<E, V, ID> extends BaseQueryService<E, V> {

    // Write operations — all @Transactional
    public V create(Map<String, Object> data) { ... }
    public V update(ID id, Map<String, Object> data) { ... }
    public List<V> bulkCreate(List<Map<String, Object>> items) { ... }
    public List<V> bulkUpdate(List<Map<String, Object>> items) { ... }
    public void delete(ID id) { ... }
    public void bulkDelete(List<ID> ids) { ... }

    // Extension points
    protected E newInstance() { /* reflection */ }
    protected void mapData(E entity, Map<String, Object> data) { /* reflection */ }
}
```

**Extension points:**

- `newInstance()` — uses `entityClass().getDeclaredConstructor().newInstance()` by default. Override if your entity requires constructor arguments or a factory method.
- `mapData(E entity, Map<String, Object> data)` — applies request data to the entity before persisting. Override to control exactly which fields are written and to handle type conversions, nested references, and security constraints.

---

## Full Working Service Example

A complete `ProductService` demonstrating all required wiring and a production-quality `mapData` override:

```java
@Service
public class ProductService extends BaseCrudService<ProductEntity, ProductDto, Long> {

    @Autowired
    public ProductService(EntityManager em, QueryPlanCompiler compiler,
                          FilterCompiler fc, OrderByCompiler oc) {
        super(em, compiler, fc, oc);
    }

    @Override
    protected Class<ProductEntity> entityClass() {
        return ProductEntity.class;
    }

    @Override
    protected ProductDto toView(ProductEntity e) {
        return new ProductDto(e.getId(), e.getName(), e.getPrice(), e.getStatus());
    }

    @Override
    protected void mapData(ProductEntity entity, Map<String, Object> data) {
        if (data.containsKey("name"))
            entity.setName((String) data.get("name"));
        if (data.containsKey("price"))
            entity.setPrice(((Number) data.get("price")).doubleValue());
        if (data.containsKey("status"))
            entity.setStatus((String) data.get("status"));
        // Never allow mass assignment of audit fields:
        // if (data.containsKey("createdBy")) entity.setCreatedBy(...) — DO NOT DO THIS
    }
}
```

**Why explicit key checks in `mapData()` matter:**

The default reflection-based `mapData()` applies every key in the data map to the entity. A malicious client sending `{"createdBy": "attacker", "price": 0.01}` would overwrite protected audit fields. An explicit `mapData()` override that only touches the fields you intend to expose is the correct approach for production code.

---

## Overriding `searchSinglePhase()` for Custom Joins

When you always need a specific to-one association loaded during search (and want to avoid N+1 without EntityGraph), override `searchSinglePhase()` to inject a custom query:

```java
@Override
protected Page<V> searchSinglePhase(QueryPlan plan) {
    // Example: always LEFT JOIN FETCH department (to-one, safe with pagination)
    // Option A — delegate to super with a custom Specification that forces a join
    Specification<UserEntity> withDept = (root, query, cb) -> {
        root.fetch("department", JoinType.LEFT);
        return cb.conjunction();  // no extra predicate
    };
    // Merge with the compiled filter spec from the plan
    // ... merge specs, call repository.findAll(merged, pageable) ...

    // Option B — fall back to default behavior
    return super.searchSinglePhase(plan);
}
```

`searchTwoPhase()` can be overridden similarly. Both overrides receive the fully compiled `QueryPlan`, including the filter `Specification`, pagination, and ordering result, so you can selectively augment rather than replace the base behavior.

---

## Thread Safety

All state in `BaseQueryService` and `BaseCrudService` is stored in Spring singleton beans:

- `em` is a Spring `EntityManager` proxy — the proxy is thread-safe; the underlying `EntityManager` instance is transaction-scoped and retrieved per-thread.
- `compiler`, `filterCompiler`, `orderByCompiler` are stateless Spring beans — safe for concurrent access.
- No mutable instance fields exist in the base classes.

Concrete service subclasses must not store request-scoped or transaction-scoped state in instance fields. All request-specific data should be passed as method parameters or stored in local variables.

---

## Constructor Injection Pattern

The recommended wiring pattern uses constructor injection, making all dependencies explicit and testable without a Spring container:

```java
@Service
public class OrderService extends BaseCrudService<OrderEntity, OrderDto, Long> {

    private final OrderMapper mapper;
    private final CustomerRepository customerRepo;

    @Autowired
    public OrderService(EntityManager em,
                        QueryPlanCompiler compiler,
                        FilterCompiler fc,
                        OrderByCompiler oc,
                        OrderMapper mapper,
                        CustomerRepository customerRepo) {
        super(em, compiler, fc, oc);
        this.mapper = mapper;
        this.customerRepo = customerRepo;
    }

    @Override
    protected Class<OrderEntity> entityClass() { return OrderEntity.class; }

    @Override
    protected OrderDto toView(OrderEntity e) { return mapper.toDto(e); }

    @Override
    protected void mapData(OrderEntity entity, Map<String, Object> data) {
        if (data.containsKey("customerId")) {
            Long customerId = ((Number) data.get("customerId")).longValue();
            entity.setCustomer(em.getReference(CustomerEntity.class, customerId));
        }
        if (data.containsKey("total"))
            entity.setTotal(new BigDecimal(data.get("total").toString()));
        if (data.containsKey("status"))
            entity.setStatus(OrderStatus.valueOf((String) data.get("status")));
    }
}
```

For unit testing, construct the service with mock collaborators directly:

```java
var service = new OrderService(mockEm, mockCompiler, mockFc, mockOc,
                               mockMapper, mockCustomerRepo);
```

No Spring context required.
