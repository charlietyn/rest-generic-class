# rest-generic-class — Configuration Reference

## Complete Properties Structure

All library properties live under the `rest-generic` prefix and are bound to `RestGenericProperties` via `@ConfigurationProperties(prefix = "rest-generic")`.

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

---

## `rest-generic.filtering.*`

### `max-depth` (default: `5`)

Controls the maximum nesting depth of the `oper` filter tree.

**What counts as depth:**
Each relation key in `oper` advances depth by 1. Logical keys (`and`, `or`) do not advance depth on their own, but a nested object inside an `and`/`or` list does advance depth.

Examples (with `max-depth: 5`):

| DSL shape                                                        | Effective depth |
|------------------------------------------------------------------|-----------------|
| `{"and": ["x|=|1"]}`                                            | 0               |
| `{"dept": {"and": ["x|=|1"]}}`                                  | 1               |
| `{"dept": {"mgr": {"and": ["x|=|1"]}}}`                         | 2               |
| `{"and": [{"or": ["x|=|1"]}]}`                                  | 1               |
| Five nested relation keys                                        | 5 (at limit)    |
| Six nested relation keys                                         | **REJECTED**    |

**What happens when the limit is exceeded:**
`DslParser.parseOperMap()` checks `depth >= maxDepth` at the top of every recursive call. If the limit is exceeded, it throws `InvalidFilterException` with the message _"Filter nesting depth exceeded maximum of N levels."_ `GlobalExceptionHandler` maps this to HTTP 400.

**Why 5 is the safe default:**
Real-world JPA entity graphs rarely need more than 3–4 levels of relation nesting. Five provides a generous margin while preventing pathological inputs like `{"a":{"b":{"c":{"d":{"e":{"f":{"g":{...}}}}}}}}` from consuming unbounded stack frames during recursive parsing.

---

### `max-conditions` (default: `100`)

Controls the total number of `ConditionNode` leaf nodes (individual `field|operator|value` strings) that a single request may contain across the entire filter tree.

**How the counter works:**
`DslParser` maintains a single-element `int[]` array (`int[] conditionCounter = {0}`) that is passed by reference through every recursive call. Java arrays are reference types, so a write in a nested recursive frame is visible to all outer frames. Before creating each `ConditionNode`, `parseConditionString()` does:

```java
if (++counter[0] > maxConditions) {
    throw new InvalidFilterException(
        "Maximum number of filter conditions (" + maxConditions + ") exceeded.");
}
```

This is a DoS protection measure. Without a limit, a malicious caller could send a request body with thousands of conditions, causing the `FilterCompiler` to build an enormous JPA `Predicate` tree and the database to process a correspondingly complex `WHERE` clause.

**Why 100:**
In practice, complex business search UIs rarely exceed 20–30 conditions. 100 is a 5× safety margin. For systems with more complex filtering needs (e.g., rule-based engines), increase this limit explicitly.

---

### `strict-relations` (default: `true`)

When `true`, every relation path in `relations` and every relation key in `oper` must be present in the entity's `@AllowedRelations` list. Any unlisted path results in HTTP 400.

When `false`, unlisted relation paths are passed through to the query layer without allowlist validation.

**Warning:** Setting this to `false` is dangerous. It allows callers to force arbitrary relation loading, potentially triggering N+1 query storms or loading sensitive related entities. Only disable strict mode for internal tooling or data migration jobs that run in a trusted context.

The default `strict-relations: true` is intentional. It is the foundation of the library's security model.

---

### `allowed-operators` (default: all 23 operators)

A list of operator symbols (case-insensitive) that callers are permitted to use. If a request uses an operator not in this list, `FilterOp.fromSymbol()` returns `Optional.empty()` and `DslParser` throws `InvalidOperatorException` → HTTP 400.

**Use cases for restricting the operator list:**

**PostgreSQL deployment — disable `regexp`:**
PostgreSQL uses `~` and `~*` for regex, not `REGEXP`. The library's `REGEXP` operator maps to `cb.like(path, raw)` (a fallback) rather than a native regex function. If you want to avoid unexpected behaviour, disable it:

```yaml
rest-generic:
  filtering:
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
```

**MySQL deployment — disable `ilike`:**
MySQL's `LIKE` is case-insensitive by default for most collations. The `ilike` operator uses `cb.lower()` on both sides, which works but adds an unnecessary function call. More importantly, if your MySQL collation is already case-insensitive, `ilike` and `like` have identical semantics, so you can safely remove `ilike` to simplify the allowed operator set.

**Public-facing API — disable `regexp` and `not regexp`:**
Regex operators are expensive on most databases. For public APIs, restricting to equality, range, and IN operators limits the complexity of generated SQL.

---

## `rest-generic.hibernate.*`

These settings mirror Hibernate configuration properties. The library reads them from `RestGenericProperties` and can use them for validation or documentation purposes. They are separate from the `spring.jpa.properties.*` namespace because they carry library-level semantics, not just raw Hibernate key-value pairs.

### `fail-on-pagination-over-collection-fetch` (default: `true`)

Maps to the Hibernate property `hibernate.query.fail_on_pagination_over_collection_fetch`.

When `true`, Hibernate throws a `HibernateException` (logged as HHH90003004) instead of silently performing in-memory pagination when a collection fetch join is combined with `LIMIT/OFFSET`.

**Why this must be true:**
Hibernate's in-memory pagination is a correctness problem, not just a performance issue. If 1,000 rows match a filter and you request page 2 of 20, Hibernate loads all 1,000 rows into memory, assembles them into a list, and then returns `list.subList(20, 40)`. The `totalElements` reported by Spring Data will reflect the in-memory slice, not the real database count. This gives wrong pagination metadata to the caller.

The library's `TwoPhaseDetector` prevents this from happening in normal operation. Setting `fail_on_pagination_over_collection_fetch=true` acts as a circuit breaker that catches any scenario where the detector is bypassed (e.g., a custom service implementation that calls `searchSinglePhase` directly on an entity with to-many relations).

**How to set it in `application.yml`:**

```yaml
spring:
  jpa:
    properties:
      hibernate.query.fail_on_pagination_over_collection_fetch: true
```

This is in addition to (not a replacement for) the `rest-generic.hibernate.*` property.

---

### `default-batch-fetch-size` (default: `32`)

Maps to the Hibernate property `hibernate.default_batch_fetch_size`.

When Hibernate loads a lazily-initialised collection or to-one association, it normally issues one SQL query per entity (the N+1 problem). With batch fetching enabled, Hibernate groups multiple uninitialized proxies into a single `WHERE id IN (?, ?, ...)` query.

A batch size of 32 means that when `toView()` accesses `entity.getRoles()` for 20 entities, Hibernate issues at most one additional query to fetch all roles for those 20 entities together, rather than 20 individual queries.

**Tuning guidance:**
- Small pages (10–20 entities): batch size of 32 is sufficient — one batch query covers the page.
- Large pages (50–100 entities): increase to 64 or 128.
- The batch size should be larger than the page size to ensure a single round-trip.

**How to set it in `application.yml`:**

```yaml
spring:
  jpa:
    properties:
      hibernate.default_batch_fetch_size: 32
```

---

## `rest-generic.cache.*`

### `enabled` (default: `false`)

Master switch for the response cache. When `false`, all other cache properties are ignored.

### `store` (default: `"redis"`)

The cache backend. Valid values: `"redis"`, `"caffeine"`, `"simple"` (in-memory). The actual cache implementation must be configured separately as a Spring `CacheManager` bean.

### `ttl` (default: `60`)

Cache time-to-live in seconds. After this period, cached entries are evicted and the next request re-executes the query.

### `cacheable-methods` (default: `["list_all", "get_one"]`)

Logical method names that should be cached. These map to the library's internal operation identifiers:

| Identifier  | Endpoint            |
|-------------|---------------------|
| `list_all`  | GET /               |
| `get_one`   | GET /{id}           |

The `search` (POST /search) endpoint is not cached by default because POST requests with body vary more widely and are not idempotent in HTTP semantics.

### `vary-headers` (default: `["Accept-Language", "X-Tenant-Id"]`)

HTTP request headers that are included in the cache key computation. This enables multi-tenant and multi-locale caching without cache poisoning.

**Multi-tenant cache key composition:**
The cache key is composed of: `entity class name + operation + request body hash + vary header values`. Two requests that differ only in `X-Tenant-Id` get separate cache entries.

**Example: tenant-aware caching:**

```yaml
rest-generic:
  cache:
    enabled: true
    store: redis
    ttl: 300
    cacheable-methods:
      - list_all
      - get_one
    vary-headers:
      - X-Tenant-Id
      - Accept-Language
```

---

## Overriding Individual Beans

Because every bean in `RestGenericAutoConfiguration` carries `@ConditionalOnMissingBean`, you can replace any component by declaring your own bean of the same type. Spring's `@ConditionalOnMissingBean` checks for beans by type (not by name), so the auto-configured bean is skipped as soon as your bean is registered.

### Example: Replace `DslParser` with Custom Limits

```java
import com.ronu.restgeneric.dsl.DslParser;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

@Configuration
public class MyRestGenericConfig {

    /**
     * Replaces the auto-configured DslParser with stricter limits.
     * max depth 3, max 50 conditions per request.
     */
    @Bean
    public DslParser dslParser() {
        return new DslParser(3, 50);
    }
}
```

### Example: Replace `AllowlistRegistry` with a Database-Backed Registry

```java
@Configuration
public class MyRestGenericConfig {

    @Bean
    public AllowlistRegistry allowlistRegistry(AllowlistConfigRepository repo) {
        DynamicAllowlistRegistry registry = new DynamicAllowlistRegistry();
        repo.findAll().forEach(cfg ->
            registry.registerRelations(cfg.getEntityClass(), cfg.getAllowedRelations()));
        return registry;
    }
}
```

### Example: Replace `GlobalExceptionHandler`

```java
@Configuration
public class MyRestGenericConfig {

    @Bean
    public GlobalExceptionHandler globalExceptionHandler() {
        return new MyCustomExceptionHandler(); // extends GlobalExceptionHandler
    }
}
```

---

## Environment-Specific Configuration Patterns

### Development

Enable SQL logging to verify generated queries:

```yaml
spring:
  jpa:
    show-sql: true
    properties:
      hibernate.format_sql: true
      hibernate.use_sql_comments: true
      hibernate.query.fail_on_pagination_over_collection_fetch: true
      hibernate.default_batch_fetch_size: 32

rest-generic:
  filtering:
    max-depth: 5
    max-conditions: 100
```

### Production

Restrict operators, disable SQL debug logging, enable caching:

```yaml
spring:
  jpa:
    show-sql: false
    properties:
      hibernate.query.fail_on_pagination_over_collection_fetch: true
      hibernate.default_batch_fetch_size: 32

rest-generic:
  filtering:
    max-depth: 5
    max-conditions: 50
    strict-relations: true
    allowed-operators:
      - "="
      - "!="
      - "<"
      - ">"
      - "<="
      - ">="
      - "like"
      - "ilike"
      - "in"
      - "not in"
      - "between"
      - "null"
      - "not null"
  cache:
    enabled: true
    store: redis
    ttl: 120
    cacheable-methods:
      - list_all
      - get_one
    vary-headers:
      - X-Tenant-Id
```

### Test / Integration Tests

Reduced limits to catch edge cases early, disable caching:

```yaml
rest-generic:
  filtering:
    max-depth: 3
    max-conditions: 20
    strict-relations: true
  cache:
    enabled: false
```

Programmatic override in test class (does not require YAML changes):

```java
@BeforeEach
void setup() {
    allowlistRegistry.registerRelations(
        UserEntity.class, Set.of("department", "roles"));
    allowlistRegistry.registerOrderBy(
        UserEntity.class, Set.of("name", "email"));
}
```

---

## Properties Quick Reference

| Property                                             | Type            | Default                         | Description                                           |
|------------------------------------------------------|-----------------|---------------------------------|-------------------------------------------------------|
| `rest-generic.filtering.max-depth`                   | `int`           | `5`                             | Max nesting depth of filter tree                      |
| `rest-generic.filtering.max-conditions`              | `int`           | `100`                           | Max total leaf conditions per request                 |
| `rest-generic.filtering.strict-relations`            | `boolean`       | `true`                          | Enforce allowlist; `false` = no validation (unsafe)   |
| `rest-generic.filtering.allowed-operators`           | `List<String>`  | All 23                          | Permitted operator symbols                            |
| `rest-generic.hibernate.fail-on-pagination-over-collection-fetch` | `boolean` | `true`         | Mirror of Hibernate's collection fetch guard          |
| `rest-generic.hibernate.default-batch-fetch-size`    | `int`           | `32`                            | Mirror of Hibernate's N+1 batch fetch setting         |
| `rest-generic.cache.enabled`                         | `boolean`       | `false`                         | Enable/disable response cache                         |
| `rest-generic.cache.store`                           | `String`        | `"redis"`                       | Cache backend (`redis`, `caffeine`, `simple`)         |
| `rest-generic.cache.ttl`                             | `int`           | `60`                            | Cache TTL in seconds                                  |
| `rest-generic.cache.cacheable-methods`               | `List<String>`  | `["list_all", "get_one"]`       | Operations to cache                                   |
| `rest-generic.cache.vary-headers`                    | `List<String>`  | `["Accept-Language", "X-Tenant-Id"]` | Headers included in cache key                   |
