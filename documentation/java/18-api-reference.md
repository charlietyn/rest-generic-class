# Chapter 18 — API Reference

This chapter is the complete, authoritative reference for every public class, interface, record,
enum, annotation, and exception in `rest-generic-class`. Entries are grouped by layer. For each
type: package, full signature, all methods with parameter descriptions, return types, checked
conditions, and usage notes.

---

## 18.1 DSL Model — `com.ronu.restgeneric.dsl.model`

### `DynamicQueryRequest`

```java
package com.ronu.restgeneric.dsl.model;

public record DynamicQueryRequest(
    Map<String, Object> oper,
    List<String> relations,
    List<Map<String, String>> orderby,
    Pagination pagination
) {}
```

The top-level request object deserialized from the HTTP request body. All four fields are
optional; any or all may be `null`. Controllers accept this record as a `@RequestBody` parameter.

| Field | Type | Nullable | Description |
|---|---|---|---|
| `oper` | `Map<String, Object>` | Yes | Filter tree. Keys are `"and"`, `"or"`, or relation names. Values are lists of condition strings or nested group objects. |
| `relations` | `List<String>` | Yes | Top-level relation names to eagerly load (e.g. `"department"`, `"roles"`). Each must be listed in `@AllowedRelations`. |
| `orderby` | `List<Map<String, String>>` | Yes | Each map has exactly one entry: `{fieldPath: "asc"|"desc"}`. Paths must appear in `@AllowedOrderBy`. |
| `pagination` | `Pagination` | Yes | Page number and page size. If `null`, `Pagination.DEFAULT` is used. |

---

### `Pagination`

```java
package com.ronu.restgeneric.dsl.model;

public record Pagination(int page, int pageSize) {

    public static final Pagination DEFAULT;          // page=1, pageSize=20

    public int offset()                              // (page - 1) * pageSize
    public int limit()                               // pageSize (alias for clarity)
    public Pageable toPageable()                     // PageRequest.of(page - 1, pageSize)

    public static Pagination of(int page, int pageSize)
        // Clamps: page >= 1; 1 <= pageSize <= 1000
        // Never throws — invalid values are silently clamped
}
```

`Pagination` is immutable. Use `Pagination.of()` for construction; direct record construction
bypasses clamping.

| Method | Returns | Notes |
|---|---|---|
| `offset()` | `int` | Zero-based row offset for SQL `OFFSET`. `page=1` → `offset=0`. |
| `limit()` | `int` | Equivalent to `pageSize`. Used to build SQL `LIMIT`. |
| `toPageable()` | `org.springframework.data.domain.Pageable` | Converts to Spring Data `PageRequest`. `page` is adjusted to zero-based index. |
| `of(int, int)` | `Pagination` | Static factory. Clamps `page` to `[1, ∞)` and `pageSize` to `[1, 1000]`. |

---

### `FilterNode` (Sealed Interface)

```java
package com.ronu.restgeneric.dsl.model;

public sealed interface FilterNode permits GroupNode, ConditionNode, RelationFilterNode {}
```

The root type of the filter tree produced by `DslParser.parseOper()`. Use `instanceof` pattern
matching or a type switch to handle each permitted subtype.

---

### `GroupNode`

```java
public record GroupNode(LogicalOp op, List<FilterNode> children) implements FilterNode {}
```

Represents a logical group (`AND` or `OR`) containing one or more child `FilterNode` instances.
Children may be `ConditionNode`, nested `GroupNode`, or `RelationFilterNode`.

| Field | Type | Description |
|---|---|---|
| `op` | `LogicalOp` | `AND` or `OR`. |
| `children` | `List<FilterNode>` | One or more child nodes. Never empty (parser rejects empty groups). |

---

### `ConditionNode`

```java
public record ConditionNode(String path, FilterOp op, List<String> values) implements FilterNode {}
```

A single leaf-level filter condition. All condition strings in the DSL (`"field|op|value"`)
parse into `ConditionNode`.

| Field | Type | Description |
|---|---|---|
| `path` | `String` | Dot-notation field path. May be a simple field (`"status"`) or a joined path (`"department.name"`). |
| `op` | `FilterOp` | One of the 23 `FilterOp` enum values. |
| `values` | `List<String>` | Parsed value list. Single string for most operators; two strings for `BETWEEN`/`NOT_BETWEEN`; N strings for `IN`/`NOT_IN`; empty for `NULL`/`NOT_NULL`/`EXISTS`/`NOT_EXISTS`. |

---

### `RelationFilterNode`

```java
public record RelationFilterNode(String relationPath, FilterNode inner) implements FilterNode {}
```

Produced when a top-level `oper` key is a relation name rather than `"and"` or `"or"`. The
`FilterCompiler` converts this into a correlated `EXISTS` subquery.

| Field | Type | Description |
|---|---|---|
| `relationPath` | `String` | The relation name as declared on the entity (e.g. `"department"`, `"roles"`). Must appear in `@AllowedRelations`. |
| `inner` | `FilterNode` | The filter tree applied inside the EXISTS subquery scope. |

---

### `OrderByItem`

```java
public record OrderByItem(String path, Direction direction) {

    public enum Direction {
        ASC, DESC;

        /**
         * Case-insensitive parse. "asc", "ASC", "Asc" → ASC. Defaults to ASC for
         * any unrecognized string.
         */
        public static Direction from(String value) { ... }
    }
}
```

One entry in the `orderby` list after parsing. Each `{fieldPath: "asc"|"desc"}` map entry
produces one `OrderByItem`.

---

### `LogicalOp` (Enum)

```java
public enum LogicalOp { AND, OR }
```

---

### `FilterOp` (Enum)

```java
public enum FilterOp {
    EQ("="),
    NE("!="),
    NE_ALT("<>"),
    GT(">"),
    GE(">="),
    LT("<"),
    LE("<="),
    LIKE("like"),
    NOT_LIKE("not like"),
    ILIKE("ilike"),
    NOT_ILIKE("not ilike"),
    IN("in"),
    NOT_IN("not in"),
    BETWEEN("between"),
    NOT_BETWEEN("not between"),
    NULL("null"),
    NOT_NULL("not null"),
    EXISTS("exists"),
    NOT_EXISTS("not exists"),
    DATE("date"),
    NOT_DATE("not date"),
    REGEXP("regexp"),
    NOT_REGEXP("not regexp");

    /**
     * Case-insensitive lookup by symbol string.
     *
     * @param symbol the operator string from the DSL (e.g. "=", "ilike", "not in")
     * @return Optional containing the matching FilterOp, or empty if not recognized
     */
    public static Optional<FilterOp> fromSymbol(String symbol) { ... }
}
```

All 23 operator symbols that may appear in the second pipe-separated segment of a condition
string. The lookup is case-insensitive: `"ILIKE"`, `"ilike"`, and `"Ilike"` all resolve to
`FilterOp.ILIKE`.

**Operator behavior notes:**

| Operator | SQL | Value Count | Notes |
|---|---|---|---|
| `EQ` | `= ?` | 1 | Exact match |
| `NE`, `NE_ALT` | `!= ?` | 1 | Both symbols map to `<>` in generated SQL |
| `GT`, `GE`, `LT`, `LE` | `> ? >= ? < ? <= ?` | 1 | Numeric or date comparison |
| `LIKE`, `NOT_LIKE` | `LIKE ?` | 1 | Caller must include `%` wildcards in the value |
| `ILIKE`, `NOT_ILIKE` | `ILIKE ?` | 1 | Case-insensitive; PostgreSQL native; falls back to `LOWER(col) LIKE LOWER(?)` on other DBs |
| `IN`, `NOT_IN` | `IN (?,?,?)` | N | Comma-separated values in DSL split into multiple bind params |
| `BETWEEN`, `NOT_BETWEEN` | `BETWEEN ? AND ?` | 2 | Exactly two comma-separated values required |
| `NULL`, `NOT_NULL` | `IS NULL`, `IS NOT NULL` | 0 | Value segment must be empty (`"field\|null\|"`) |
| `EXISTS`, `NOT_EXISTS` | Used internally by `RelationFilterNode` compilation | 0 | Not typically written directly in conditions |
| `DATE`, `NOT_DATE` | `CAST(col AS DATE) = ?` | 1 | Strips time component; value must be `YYYY-MM-DD` |
| `REGEXP`, `NOT_REGEXP` | `REGEXP_LIKE(col, ?)` | 1 | Database-specific; PostgreSQL `~`, MySQL `REGEXP` |

---

## 18.2 DSL Parsing — `com.ronu.restgeneric.dsl`

### `DslParser`

```java
package com.ronu.restgeneric.dsl;

public class DslParser {

    /**
     * Default constructor. Uses maxDepth=5 and maxConditions=100.
     */
    public DslParser() { ... }

    /**
     * Full constructor.
     *
     * @param maxDepth       maximum nesting depth for oper groups (must be >= 1)
     * @param maxConditions  maximum total condition nodes across the entire filter tree
     */
    public DslParser(int maxDepth, int maxConditions) { ... }

    /**
     * Parses the oper map into a FilterNode tree.
     *
     * @param oper  the raw Map from DynamicQueryRequest.oper(); may be null
     * @return Optional.empty() if oper is null or empty; Optional containing the root FilterNode
     * @throws InvalidFilterException    if nesting depth exceeds maxDepth, total conditions
     *                                   exceed maxConditions, or a condition string is malformed
     * @throws InvalidOperatorException  if a condition string contains an unrecognized operator
     *                                   symbol
     */
    public Optional<FilterNode> parseOper(Map<String, Object> oper) { ... }

    /**
     * Parses the orderby list.
     *
     * @param orderby  the raw List from DynamicQueryRequest.orderby(); may be null
     * @return empty list if orderby is null; parsed OrderByItem list otherwise
     * @throws InvalidFilterException  if an entry's direction string is unrecognized
     */
    public List<OrderByItem> parseOrderBy(List<Map<String, String>> orderby) { ... }

    /**
     * Parses the relations list.
     *
     * @param relations  the raw List from DynamicQueryRequest.relations(); may be null
     * @return empty list if relations is null; the list unchanged otherwise
     *         (validation is performed later by PathValidator, not here)
     */
    public List<String> parseRelations(List<String> relations) { ... }

    /**
     * Parses and clamps pagination parameters.
     *
     * @param pagination  the Pagination record from DynamicQueryRequest; may be null
     * @return Pagination.DEFAULT if null; clamped Pagination otherwise
     *         (page >= 1; 1 <= pageSize <= 1000)
     */
    public Pagination parsePagination(Pagination pagination) { ... }
}
```

---

## 18.3 Allowlist and Validation — `com.ronu.restgeneric.validation`

### `AllowlistRegistry`

```java
package com.ronu.restgeneric.validation;

public class AllowlistRegistry {

    /**
     * Reads @AllowedRelations and @AllowedOrderBy annotations from the entity class and stores
     * them in the registry. This method is idempotent — calling it multiple times for the same
     * class has no effect after the first call.
     *
     * @param entityClass  the JPA entity class to register
     */
    public void register(Class<?> entityClass) { ... }

    /**
     * Programmatic override for the relation allowlist. Replaces any annotation-based or
     * previously registered relations for this entity class.
     *
     * @param entityClass  the JPA entity class
     * @param relations    the exact set of relation paths that are permitted
     */
    public void registerRelations(Class<?> entityClass, Set<String> relations) { ... }

    /**
     * Programmatic override for the order-by allowlist. Replaces any annotation-based or
     * previously registered order-by paths for this entity class.
     *
     * @param entityClass  the JPA entity class
     * @param paths        the exact set of field paths that may appear in orderby[]
     */
    public void registerOrderBy(Class<?> entityClass, Set<String> paths) { ... }

    /**
     * @return true if the given path is in the registered relation allowlist for this entity
     */
    public boolean isRelationAllowed(Class<?> entityClass, String path) { ... }

    /**
     * @return true if the given path is in the registered order-by allowlist for this entity
     */
    public boolean isOrderByAllowed(Class<?> entityClass, String path) { ... }

    /**
     * @return an immutable copy of the registered relation paths, or an empty set if the
     *         entity has not been registered
     */
    public Set<String> getAllowedRelations(Class<?> entityClass) { ... }

    /**
     * @return an immutable copy of the registered order-by paths, or an empty set if the
     *         entity has not been registered
     */
    public Set<String> getAllowedOrderBy(Class<?> entityClass) { ... }
}
```

---

### `PathValidator`

```java
package com.ronu.restgeneric.validation;

public class PathValidator {

    /**
     * @param registry  the allowlist source
     * @param emf       used to walk the JPA metamodel for isToManyPath()
     * @param maxDepth  maximum path segment depth
     */
    public PathValidator(AllowlistRegistry registry,
                         EntityManagerFactory emf,
                         int maxDepth) { ... }

    /**
     * Validates that a relation path is permitted for the given root entity.
     *
     * @param rootEntity    the root JPA entity class
     * @param relationPath  dot-notation path (e.g. "department", "orders.items")
     * @throws InvalidRelationException      if the path is not in @AllowedRelations
     * @throws PathDepthExceededException    if the number of dot-segments exceeds maxDepth
     */
    public void validateRelationPath(Class<?> rootEntity, String relationPath) { ... }

    /**
     * Validates that an order-by path is permitted for the given root entity.
     *
     * @param rootEntity  the root JPA entity class
     * @param path        the order-by path (e.g. "name", "department.name")
     * @throws InvalidRelationException  if the path is not in @AllowedOrderBy
     */
    public void validateOrderByPath(Class<?> rootEntity, String path) { ... }

    /**
     * Walks the JPA metamodel to determine whether the attribute at the end of the given
     * path segments is a plural (to-many) association.
     *
     * @param rootEntity  the root entity class to start metamodel traversal from
     * @param segments    dot-notation path split by "." (e.g. ["roles"] or ["orders", "items"])
     * @return true  if the terminal attribute is a {@code PluralAttribute} (OneToMany, ManyToMany)
     * @return false if the terminal attribute is a {@code SingularAttribute} (ManyToOne, OneToOne)
     *              or a scalar field, or if the path cannot be resolved
     */
    public boolean isToManyPath(Class<?> rootEntity, String[] segments) { ... }
}
```

---

## 18.4 Query Compilation — `com.ronu.restgeneric.query`

### `FilterCompiler`

```java
package com.ronu.restgeneric.query;

public class FilterCompiler {

    /**
     * Converts a FilterNode tree into a Spring Data JPA Specification.
     *
     * @param root  the root FilterNode; may be null
     * @param <E>   the JPA entity type
     * @return Specification.where(null) (matches all rows) if root is null;
     *         otherwise a Specification that applies the full predicate tree
     */
    public <E> Specification<E> toSpecification(FilterNode root) { ... }
}
```

**Predicate compilation behavior by node type:**

| Node Type | Compiled to |
|---|---|
| `GroupNode(AND, children)` | `cb.and(children.stream().map(compile).toArray(Predicate[]::new))` |
| `GroupNode(OR, children)` | `cb.or(...)` |
| `ConditionNode(path, EQ, ["val"])` | `cb.equal(root.get(path), coerce("val"))` |
| `ConditionNode(path, IN, [...])` | `root.get(path).in(values.stream().map(coerce)...)` |
| `ConditionNode(path, BETWEEN, [a, b])` | `cb.between(root.get(path), coerce(a), coerce(b))` |
| `ConditionNode(path, NULL, [])` | `cb.isNull(root.get(path))` |
| `ConditionNode(path, LIKE, [v])` | `cb.like(root.get(path), v)` |
| `ConditionNode(path, ILIKE, [v])` | `cb.like(cb.lower(root.get(path)), v.toLowerCase())` |
| `RelationFilterNode(rel, inner)` | Correlated EXISTS subquery on the relation join |

**Type coercion rules for `ConditionNode` values:**

| Input String | Coerced To |
|---|---|
| `null` or `""` | `null` |
| `"true"` / `"false"` (case-insensitive) | `Boolean` |
| Parseable as `Long` | `Long` |
| Parseable as `Double` | `Double` |
| Anything else | `String` |

Coercion is applied per value. For `IN`/`NOT_IN`, each element is coerced independently.

---

### `OrderByCompiler`

```java
package com.ronu.restgeneric.query;

public class OrderByCompiler {

    public OrderByCompiler(AllowlistRegistry registry, PathValidator pathValidator) { ... }

    /**
     * Compiles a list of OrderByItem into a Sort and an optional subquery Specification for
     * to-many path ordering.
     *
     * @param items               parsed order-by items from DslParser.parseOrderBy()
     * @param rootEntity          the root JPA entity class
     * @param relationIsToMany    map of top-level relation segment → whether it is to-many;
     *                            used to route relational order paths to subquery compilation
     * @return a Result containing Sort (for local + to-one paths) and an optional
     *         Specification (for to-many paths compiled as scalar correlated subqueries)
     * @throws InvalidRelationException  if any path is not in @AllowedOrderBy
     */
    public <E> Result<E> compile(List<OrderByItem> items,
                                  Class<E> rootEntity,
                                  Map<String, Boolean> relationIsToMany) { ... }

    /**
     * Result of OrderByCompiler.compile().
     *
     * @param sort               Spring Data Sort for local and to-one paths; may be Sort.unsorted()
     * @param subqueryOrderSpec  Specification that appends ORDER BY scalar subquery for to-many
     *                           paths; null if no to-many paths were present
     */
    public record Result<E>(Sort sort, Specification<E> subqueryOrderSpec) {

        /**
         * @return true if subqueryOrderSpec is non-null (to-many path ordering is required)
         */
        public boolean hasSubqueryOrdering() { ... }
    }
}
```

---

### `QueryPlan`

```java
package com.ronu.restgeneric.query;

public record QueryPlan(
    FilterNode filterTree,               // null if no oper was provided
    List<OrderByItem> orderItems,        // empty list if no orderby
    List<String> requestedRelations,     // empty list if no relations
    boolean requiresTwoPhase,           // true if paginated + any to-many relation
    Pagination pagination,               // never null (defaults to Pagination.DEFAULT)
    Map<String, Boolean> relationIsToMany  // top-level relation segment → isToMany
) {

    /**
     * @return true if pagination was explicitly provided in the request
     *         (i.e. pagination != Pagination.DEFAULT, or more precisely:
     *          the original request had a non-null pagination field)
     */
    public boolean isPaginated() { ... }
}
```

`QueryPlan` is the compiled, validated, ready-to-execute representation of a
`DynamicQueryRequest`. `BaseQueryService` receives a `QueryPlan` from `QueryPlanCompiler`
and uses it to determine the query execution strategy.

---

### `QueryPlanCompiler`

```java
package com.ronu.restgeneric.query;

public class QueryPlanCompiler {

    public QueryPlanCompiler(DslParser parser,
                              AllowlistRegistry registry,
                              PathValidator pathValidator,
                              TwoPhaseDetector twoPhaseDetector) { ... }

    /**
     * Parses and validates all fields of the request, resolves metamodel metadata, and
     * compiles the result into a QueryPlan.
     *
     * Calls registry.register(rootEntity) — this is idempotent and safe to call on every
     * request. Annotation-based registration runs once and is cached thereafter.
     *
     * @param request     the raw DynamicQueryRequest from the controller
     * @param rootEntity  the JPA entity class being queried
     * @return a fully validated QueryPlan
     * @throws InvalidFilterException        malformed oper (depth exceeded, condition limit, bad format)
     * @throws InvalidOperatorException      unknown operator symbol in a condition string
     * @throws InvalidRelationException      relation or orderby path not in the allowlist
     * @throws PathDepthExceededException    path depth exceeds the configured maximum
     */
    public QueryPlan compile(DynamicQueryRequest request, Class<?> rootEntity) { ... }
}
```

---

### `TwoPhaseDetector`

```java
package com.ronu.restgeneric.query;

public class TwoPhaseDetector {

    public TwoPhaseDetector(PathValidator pathValidator) { ... }

    /**
     * Determines whether the search requires the two-phase pagination strategy.
     *
     * Two-phase is required when ALL of the following are true:
     * - pagination is non-null
     * - at least one relation in the list is a to-many path (OneToMany or ManyToMany)
     *
     * @param rootEntity  the root JPA entity class
     * @param relations   the list of requested relation paths (from the DSL request)
     * @param pagination  the pagination from the request; may be null
     * @return false if pagination is null, regardless of relations
     * @return false if all relations are to-one (ManyToOne, OneToOne)
     * @return true  if pagination is non-null AND at least one relation is to-many
     */
    public boolean requiresTwoPhase(Class<?> rootEntity,
                                     List<String> relations,
                                     Pagination pagination) { ... }
}
```

---

## 18.5 Service Layer — `com.ronu.restgeneric.service`

### `BaseQueryService<E, V>`

```java
package com.ronu.restgeneric.service;

public abstract class BaseQueryService<E, V> {

    protected BaseQueryService(EntityManager em,
                                QueryPlanCompiler compiler,
                                FilterCompiler filterCompiler,
                                OrderByCompiler orderByCompiler) { ... }

    // -----------------------------------------------------------------
    // Abstract methods — must be implemented by subclasses
    // -----------------------------------------------------------------

    /**
     * @return the JPA entity class this service operates on
     */
    protected abstract Class<E> entityClass();

    /**
     * Maps a single entity instance to the view/DTO type V.
     * Called once per entity in search results, findById, and CRUD responses.
     *
     * @param entity  a managed entity instance loaded within an active transaction
     * @return the view object to return to the controller
     */
    protected abstract V toView(E entity);

    // -----------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------

    /**
     * Executes a dynamic search based on the DSL request.
     * Automatically chooses single-phase or two-phase strategy.
     *
     * @param request  the deserialized request body
     * @return a Page<V> containing the current page of results and total element count
     * @throws InvalidFilterException, InvalidRelationException, InvalidOperatorException,
     *         PathDepthExceededException  — propagated from QueryPlanCompiler
     */
    public Page<V> search(DynamicQueryRequest request) { ... }

    /**
     * Finds a single entity by primary key and converts it to V.
     *
     * @param id  the primary key value (any type supported by JPA em.find())
     * @return Optional.empty() if not found
     */
    public Optional<V> findById(Object id) { ... }

    /**
     * Finds a single entity by primary key and eagerly loads the specified relations.
     *
     * @param id        the primary key value
     * @param relations relation paths to include in the EntityGraph
     * @return Optional.empty() if not found
     * @throws InvalidRelationException  if any relation is not in @AllowedRelations
     */
    public Optional<V> findByIdWithRelations(Object id, List<String> relations) { ... }

    // -----------------------------------------------------------------
    // Protected extension points — override for customization
    // -----------------------------------------------------------------

    /**
     * Executes a standard single-phase paginated query.
     * Used when requiresTwoPhase is false.
     *
     * @param plan  the compiled query plan
     * @return paged results
     */
    protected Page<V> searchSinglePhase(QueryPlan plan) { ... }

    /**
     * Executes the two-phase paginated strategy:
     * Phase 1 — count query + scalar ID query (no collection join)
     * Phase 2 — entity + relation query scoped to the Phase 1 ID set
     *
     * @param plan  the compiled query plan (requiresTwoPhase must be true)
     * @return paged results with correct totalElements count
     */
    protected Page<V> searchTwoPhase(QueryPlan plan) { ... }

    /**
     * Executes a COUNT query for the given Specification.
     *
     * @param spec  the Specification; may be null (counts all rows)
     * @return total matching row count
     */
    protected long count(Specification<E> spec) { ... }

    /**
     * Executes a scalar ID-only query, used in Phase 1 of the two-phase strategy.
     *
     * @param spec         the filter Specification
     * @param orderResult  compiled order-by result; Sort is applied here
     * @param pg           pagination to apply
     * @return list of primary key values for the current page
     */
    protected List<Object> fetchIds(Specification<E> spec,
                                    OrderByCompiler.Result<E> orderResult,
                                    Pagination pg) { ... }

    /**
     * Fetches full entity instances (with relation EntityGraph), used in Phase 2 and
     * in single-phase searches.
     *
     * @param spec         the filter Specification (or IN-ID specification in Phase 2)
     * @param orderResult  compiled order-by result
     * @param pg           pagination to apply (may be null for unpaginated searches)
     * @return list of managed entity instances
     */
    protected List<E> fetchEntities(Specification<E> spec,
                                    OrderByCompiler.Result<E> orderResult,
                                    Pagination pg) { ... }
}
```

---

### `BaseCrudService<E, V, ID>`

```java
package com.ronu.restgeneric.service;

public abstract class BaseCrudService<E, V, ID> extends BaseQueryService<E, V> {

    // -----------------------------------------------------------------
    // Public CRUD API — all write methods are @Transactional
    // -----------------------------------------------------------------

    /**
     * Creates a new entity from the provided data map.
     * Calls newInstance() to create the entity, mapData() to populate it, then persists.
     *
     * @param data  field-value map from the request body
     * @return the view of the newly created entity (with database-assigned ID)
     */
    @Transactional
    public V create(Map<String, Object> data) { ... }

    /**
     * Updates an existing entity by ID.
     *
     * @param id    the primary key of the entity to update
     * @param data  field-value map containing the fields to update
     * @return the view of the updated entity
     * @throws EntityNotFoundException  if no entity exists for the given id
     */
    @Transactional
    public V update(ID id, Map<String, Object> data) { ... }

    /**
     * Creates multiple entities in a single transaction.
     *
     * @param items  list of field-value maps; each becomes one entity
     * @return list of views for all created entities, in input order
     */
    @Transactional
    public List<V> bulkCreate(List<Map<String, Object>> items) { ... }

    /**
     * Updates multiple entities in a single transaction. Each item must contain an "id" key.
     *
     * @param items  list of field-value maps; each must have "id" key
     * @return list of views for all updated entities, in input order
     * @throws IllegalArgumentException  if any item is missing the "id" key
     * @throws EntityNotFoundException   if any referenced entity does not exist
     */
    @Transactional
    public List<V> bulkUpdate(List<Map<String, Object>> items) { ... }

    /**
     * Deletes an entity by primary key.
     *
     * @param id  the primary key of the entity to delete
     * @throws EntityNotFoundException  if no entity exists for the given id
     */
    @Transactional
    public void delete(ID id) { ... }

    /**
     * Deletes multiple entities by primary key in a single transaction.
     * Silently skips IDs that do not exist.
     *
     * @param ids  list of primary keys to delete
     */
    @Transactional
    public void bulkDelete(List<ID> ids) { ... }

    // -----------------------------------------------------------------
    // Protected extension points
    // -----------------------------------------------------------------

    /**
     * Creates a new, empty entity instance.
     * Default implementation uses reflection (entityClass().getDeclaredConstructor().newInstance()).
     * Override if the entity requires constructor arguments or factory method instantiation.
     *
     * @return a new, unmanaged entity instance
     */
    protected E newInstance() { ... }

    /**
     * Maps the data map's fields onto the entity. Called by create() and update().
     * Default implementation uses reflection to set fields matching map keys.
     *
     * IMPORTANT: Always override this method for any entity with sensitive fields.
     * See AP-3 in Chapter 16.
     *
     * @param entity  the entity instance to populate (may be new or existing)
     * @param data    the field-value map from the request body
     */
    protected void mapData(E entity, Map<String, Object> data) { ... }
}
```

---

## 18.6 Controller Layer — `com.ronu.restgeneric.controller`

### `BaseRestController<E, V, ID>`

```java
package com.ronu.restgeneric.controller;

public abstract class BaseRestController<E, V, ID> {

    /**
     * @return the CRUD service this controller delegates to
     */
    protected abstract BaseCrudService<E, V, ID> service();
}
```

**Endpoint table — override `@RequestMapping` on the concrete class to set the base path:**

| Method | Path | Request Body | Response Status | Response Body | Description |
|---|---|---|---|---|---|
| `GET` | `/` | `DynamicQueryRequest` (query params or body) | `200 OK` | `Page<V>` | List all with optional filtering |
| `POST` | `/search` | `DynamicQueryRequest` (body) | `200 OK` | `Page<V>` | Full DSL search |
| `GET` | `/{id}` | — | `200 OK` / `404` | `V` | Find by ID |
| `GET` | `/{id}/with` | `relations` query param | `200 OK` / `404` | `V` | Find by ID with named relations loaded |
| `POST` | `/` | `Map<String, Object>` | `201 Created` | `V` | Create single entity |
| `PUT` | `/{id}` | `Map<String, Object>` | `200 OK` | `V` | Update single entity |
| `POST` | `/bulk` | `List<Map<String, Object>>` | `201 Created` | `List<V>` | Bulk create |
| `PUT` | `/bulk` | `List<Map<String, Object>>` | `200 OK` | `List<V>` | Bulk update (each item must have `"id"`) |
| `DELETE` | `/{id}` | — | `204 No Content` | — | Delete single entity |
| `DELETE` | `/bulk` | `List<ID>` | `204 No Content` | — | Bulk delete |

**Concrete controller example:**

```java
@RestController
@RequestMapping("/api/users")
public class UserController extends BaseRestController<UserEntity, UserDto, Long> {

    @Autowired
    private UserService userService;

    @Override
    protected BaseCrudService<UserEntity, UserDto, Long> service() {
        return userService;
    }
}
```

---

## 18.7 Annotations — `com.ronu.restgeneric.annotation`

### `@AllowedRelations`

```java
@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
public @interface AllowedRelations {
    /**
     * The relation paths that may appear in the relations[] array and as oper relation keys.
     * Each path is a dot-notation string (e.g. "department", "orders.items").
     */
    String[] value();
}
```

Place on the JPA entity class. Paths not listed here throw `InvalidRelationException` (HTTP 400)
when requested. Read by `AllowlistRegistry.register()`.

---

### `@AllowedOrderBy`

```java
@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
public @interface AllowedOrderBy {
    /**
     * The field paths that may appear in the orderby[] array.
     * Includes both local paths ("name", "createdAt") and relational paths ("department.name").
     * Independent of @AllowedRelations — a relational path must be listed here explicitly.
     */
    String[] value();
}
```

---

### `@FieldsByRole`

```java
@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
public @interface FieldsByRole {
    RoleFields[] value();
}

@Target({})  // annotation type — not directly applied
@Retention(RetentionPolicy.RUNTIME)
public @interface RoleFields {
    /** The role name (e.g. "ROLE_ADMIN", "ROLE_USER"). */
    String role();

    /** Fields visible to this role. Fields not listed are excluded from the view. */
    String[] fields();
}
```

Enables role-based field visibility. The response serialization layer reads these annotations
to include or exclude fields based on the current security context's roles.

**Example:**

```java
@Entity
@FieldsByRole({
    @RoleFields(role = "ROLE_ADMIN",  fields = {"id", "name", "email", "isAdmin", "createdAt"}),
    @RoleFields(role = "ROLE_USER",   fields = {"id", "name", "email"})
})
public class UserEntity { ... }
```

---

### `@CacheInvalidates`

```java
@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
public @interface CacheInvalidates {
    /**
     * Cache key prefixes to evict when this entity is written (created, updated, or deleted).
     * Example: if writing to UserEntity should also evict "department-summary" cache keys,
     * list "department-summary" here.
     */
    String[] value();
}
```

Used by the optional cache layer. When a write operation completes on an annotated entity, all
cache entries matching any listed key prefix are evicted.

---

## 18.8 Exceptions — `com.ronu.restgeneric.exception`

All exceptions are unchecked (`RuntimeException`) and extend `RestGenericException`. They are
handled by `GlobalExceptionHandler`, which maps them to appropriate HTTP status codes.

```java
package com.ronu.restgeneric.exception;

/** Base class for all rest-generic exceptions. */
public class RestGenericException extends RuntimeException {
    public RestGenericException(String message) { ... }
    public RestGenericException(String message, Throwable cause) { ... }
}

/** Thrown when the oper structure is malformed, depth is exceeded, or conditions exceed the limit. */
public class InvalidFilterException extends RestGenericException { ... }
// HTTP mapping: 400 Bad Request

/** Thrown when a condition string contains an unrecognized operator symbol. */
public class InvalidOperatorException extends RestGenericException {
    /** @return the unrecognized operator symbol that caused the exception */
    public String getSymbol() { ... }
}
// HTTP mapping: 400 Bad Request

/** Thrown when a relation path or order-by path is not in the entity's allowlist. */
public class InvalidRelationException extends RestGenericException {
    /** @return the path that was rejected */
    public String getPath() { ... }
    /** @return the entity class whose allowlist was consulted */
    public Class<?> getEntityClass() { ... }
}
// HTTP mapping: 400 Bad Request

/** Thrown when a dot-notation path exceeds the configured maximum depth. */
public class PathDepthExceededException extends RestGenericException {
    /** @return the path that exceeded the limit */
    public String getPath() { ... }
    /** @return the configured maximum depth */
    public int getMaxDepth() { ... }
}
// HTTP mapping: 400 Bad Request
```

---

## 18.9 Exception Handler — `com.ronu.restgeneric.exception`

### `GlobalExceptionHandler`

```java
package com.ronu.restgeneric.exception;

@RestControllerAdvice
public class GlobalExceptionHandler {

    // rest-generic exceptions → 400
    @ExceptionHandler({
        InvalidFilterException.class,
        InvalidOperatorException.class,
        InvalidRelationException.class,
        PathDepthExceededException.class
    })
    public ResponseEntity<ErrorResponse> handleBadRequest(RestGenericException ex) { ... }

    // Bean validation → 422
    @ExceptionHandler(ConstraintViolationException.class)
    public ResponseEntity<ErrorResponse> handleValidation(ConstraintViolationException ex) { ... }

    // Entity not found → 500 by default; subclass and override to return 404
    @ExceptionHandler(EntityNotFoundException.class)
    public ResponseEntity<ErrorResponse> handleNotFound(EntityNotFoundException ex) { ... }
}
```

Override `GlobalExceptionHandler` to customize error response shape or change status codes.
Because `@ConditionalOnMissingBean` is applied, declaring your own `@RestControllerAdvice` bean
(or a bean of type `GlobalExceptionHandler`) suppresses the default handler entirely:

```java
@RestControllerAdvice
public class MyExceptionHandler extends GlobalExceptionHandler {

    @Override
    public ResponseEntity<ErrorResponse> handleNotFound(EntityNotFoundException ex) {
        // Override to return 404 instead of 500
        return ResponseEntity.notFound().build();
    }
}
```

---

## 18.10 Configuration — `com.ronu.restgeneric.config`

### `RestGenericAutoConfiguration`

```java
package com.ronu.restgeneric.config;

@Configuration
@EnableConfigurationProperties(RestGenericProperties.class)
@ConditionalOnClass(EntityManager.class)
public class RestGenericAutoConfiguration {

    @Bean
    @ConditionalOnMissingBean
    public DslParser dslParser(RestGenericProperties props) { ... }

    @Bean
    @ConditionalOnMissingBean
    public AllowlistRegistry allowlistRegistry() { ... }

    @Bean
    @ConditionalOnMissingBean
    public PathValidator pathValidator(AllowlistRegistry registry,
                                       EntityManagerFactory emf,
                                       RestGenericProperties props) { ... }

    @Bean
    @ConditionalOnMissingBean
    public FilterCompiler filterCompiler() { ... }

    @Bean
    @ConditionalOnMissingBean
    public OrderByCompiler orderByCompiler(AllowlistRegistry registry,
                                           PathValidator pathValidator) { ... }

    @Bean
    @ConditionalOnMissingBean
    public TwoPhaseDetector twoPhaseDetector(PathValidator pathValidator) { ... }

    @Bean
    @ConditionalOnMissingBean
    public QueryPlanCompiler queryPlanCompiler(DslParser parser,
                                               AllowlistRegistry registry,
                                               PathValidator pathValidator,
                                               TwoPhaseDetector twoPhaseDetector) { ... }

    @Bean
    @ConditionalOnMissingBean
    public GlobalExceptionHandler globalExceptionHandler() { ... }
}
```

Every bean is annotated with `@ConditionalOnMissingBean`. To replace any bean, declare your own
`@Bean` of the same type in any `@Configuration` class. The auto-configuration will skip its
default definition.

---

### `RestGenericProperties`

```java
package com.ronu.restgeneric.config;

@ConfigurationProperties(prefix = "rest-generic")
public class RestGenericProperties {

    public Filtering filtering = new Filtering();
    public Hibernate hibernate = new Hibernate();
    public Cache cache = new Cache();

    public static class Filtering {
        /** Maximum oper nesting depth. Default: 5 */
        public int maxDepth = 5;

        /** Maximum total conditions across the entire filter tree. Default: 100 */
        public int maxConditions = 100;

        /**
         * Whether to enforce @AllowedRelations checks.
         * true (default): unlisted relation paths throw InvalidRelationException.
         * false: all relation paths are permitted — DANGEROUS in production.
         */
        public boolean strictRelations = true;

        /**
         * Restrict which operators are usable. Default: all 23 operators.
         * Example to disable REGEXP: ["eq","ne","gt","ge","lt","le","like","not like","ilike",
         * "not ilike","in","not in","between","not between","null","not null","exists",
         * "not exists","date","not date"]
         */
        public List<String> allowedOperators = /* all 23 */ ...;
    }

    public static class Hibernate {
        /**
         * Automatically sets hibernate.query.fail_on_pagination_over_collection_fetch=true.
         * Never set to false. Default: true.
         */
        public boolean failOnPaginationOverCollectionFetch = true;

        /**
         * Sets hibernate.default_batch_fetch_size for N+1 mitigation on lazy associations.
         * Default: 32
         */
        public int defaultBatchFetchSize = 32;
    }

    public static class Cache {
        /** Enable the optional response cache layer. Default: false */
        public boolean enabled = false;

        /** Cache store backend. "redis" or "caffeine". Default: "redis" */
        public String store = "redis";

        /** Cache TTL in seconds. Default: 60 */
        public int ttl = 60;

        /** Method names whose results are cached. Default: ["list_all", "get_one"] */
        public List<String> cacheableMethods = List.of("list_all", "get_one");

        /**
         * HTTP request headers used as part of the cache key.
         * Useful for multi-tenant or multi-locale deployments.
         * Default: ["Accept-Language", "X-Tenant-Id"]
         */
        public List<String> varyHeaders = List.of("Accept-Language", "X-Tenant-Id");
    }
}
```

**`application.yml` reference:**

```yaml
rest-generic:
  filtering:
    max-depth: 5
    max-conditions: 100
    strict-relations: true
    allowed-operators:
      - "="
      - "!="
      - ">"
      - ">="
      - "<"
      - "<="
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

---

## 18.11 Quick Method Lookup Index

| What you want to do | Use |
|---|---|
| Parse a DSL request | `DslParser.parseOper()`, `parseOrderBy()`, `parseRelations()`, `parsePagination()` |
| Check if a relation is allowed | `AllowlistRegistry.isRelationAllowed()` |
| Register relations programmatically | `AllowlistRegistry.registerRelations()` |
| Validate a path against the JPA model | `PathValidator.validateRelationPath()` |
| Determine if a path is to-many | `PathValidator.isToManyPath()` |
| Compile a filter tree to a Specification | `FilterCompiler.toSpecification()` |
| Compile orderby to Sort + subquery spec | `OrderByCompiler.compile()` |
| Determine if two-phase is needed | `TwoPhaseDetector.requiresTwoPhase()` |
| Compile a full DSL request | `QueryPlanCompiler.compile()` |
| Execute a dynamic search | `BaseQueryService.search()` |
| Find by ID | `BaseQueryService.findById()` |
| Find by ID with relations | `BaseQueryService.findByIdWithRelations()` |
| Create an entity | `BaseCrudService.create()` |
| Update an entity | `BaseCrudService.update()` |
| Delete an entity | `BaseCrudService.delete()` |
| Bulk create | `BaseCrudService.bulkCreate()` |
| Bulk update | `BaseCrudService.bulkUpdate()` |
| Bulk delete | `BaseCrudService.bulkDelete()` |
| Customize entity instantiation | Override `BaseCrudService.newInstance()` |
| Guard against mass assignment | Override `BaseCrudService.mapData()` |
| Customize query execution | Override `BaseQueryService.searchSinglePhase()` or `searchTwoPhase()` |
| Replace any framework bean | Declare a `@Bean` of the same type — `@ConditionalOnMissingBean` suppresses the default |
