# rest-generic-class — Java / Spring Boot Library

> Generic REST CRUD with dynamic DSL filtering, two-phase pagination, and Blaze-Persistence
> Entity Views for Spring Boot 3

---

## Overview

`rest-generic-class` is a Spring Boot library that eliminates boilerplate from REST API
development. Instead of writing a custom `findByStatusAndDepartmentAndCreatedAtBetween` method
for every filter combination a UI might need, you expose a single search endpoint that accepts a
structured JSON filter tree. The library parses, validates, and compiles that tree into type-safe
JPA `Specification` objects at runtime.

The filter DSL uses a simple pipe-separated notation — `"field|operator|value"` — grouped into
AND and OR trees. The same JSON contract is shared by the companion PHP Laravel library. A
client application written against the Laravel version of the API can migrate to the Spring Boot
version transparently: the request body format, operator symbols, pagination keys, and response
shape are identical. The execution layer differs (Hibernate instead of Eloquent), but the
wire protocol does not.

Security is enforced through an allowlist model. Every JPA entity that participates in dynamic
queries must declare exactly which relations and order-by paths are permitted, using
`@AllowedRelations` and `@AllowedOrderBy` annotations. Paths not listed in the allowlist are
rejected with HTTP 400 before any query is built. This prevents relation traversal attacks,
denial-of-service through deep JOIN chains, and accidental exposure of internal associations.

The library is designed to stay out of the way. Every bean registered by `RestGenericAutoConfiguration`
is annotated with `@ConditionalOnMissingBean`. To replace any component — the filter compiler,
the exception handler, the DSL parser, or the entire query plan compiler — declare your own
`@Bean` of the same type. The framework's default is suppressed automatically. There are no
mandatory base classes beyond `BaseQueryService` and `BaseCrudService`; there is no code
generation and no annotation processor.

---

## Design Contract — Shared DSL with the PHP Laravel Version

The JSON DSL is the same across both implementations. A frontend that constructs this request
body works against either backend without modification:

```json
{
  "oper": {
    "and": [
      "status|=|ACTIVE",
      "createdAt|>=|2024-01-01"
    ],
    "department": {
      "and": ["active|=|true"]
    }
  },
  "relations": ["department", "roles"],
  "orderby": [
    {"name": "asc"},
    {"id": "asc"}
  ],
  "pagination": {
    "page": 1,
    "pageSize": 20
  }
}
```

The Java implementation compiles `oper` to a JPA `Specification` with a correlated `EXISTS`
subquery for the `department` key, builds a `JOIN FETCH` EntityGraph for `relations`, and applies
the two-phase pagination strategy when any relation is a `@ManyToMany` or `@OneToMany`. The PHP
implementation generates equivalent Eloquent/SQL. The contract — field names, operator symbols,
group keys, pagination keys — is never changed without a coordinated version bump on both sides.

---

## Prerequisites

| Requirement | Minimum Version |
|---|---|
| Java | 21 |
| Spring Boot | 3.3.0 |
| Hibernate | 6.x (via Spring Boot) |
| Maven | 3.8+ |
| Jakarta EE | 10 |

The library requires Java 21. It uses sealed interfaces (`FilterNode`), records (`QueryPlan`,
`Pagination`, `ConditionNode`), and pattern matching, all of which require Java 16+. Java 21 is
the minimum supported LTS release.

Spring Boot 3.x requires Jakarta EE 10. There is no `javax.*` dependency anywhere in the
library. If you are using Blaze-Persistence, use the Jakarta EE–compatible module (Chapter 13).

---

## Quick Start in 5 Steps

A complete walkthrough with Maven coordinates, configuration, and entity setup is in Chapter 02.
The abbreviated version:

**Step 1 — Add the dependency:**
```xml
<dependency>
    <groupId>com.ronu</groupId>
    <artifactId>rest-generic-class</artifactId>
    <version>1.0.0</version>
</dependency>
```

**Step 2 — Annotate your entity:**
```java
@Entity
@AllowedRelations({"department"})
@AllowedOrderBy({"name", "createdAt", "department.name"})
public class UserEntity { ... }
```

**Step 3 — Create the service:**
```java
@Service
public class UserService extends BaseCrudService<UserEntity, UserDto, Long> {
    @Override protected Class<UserEntity> entityClass() { return UserEntity.class; }
    @Override protected UserDto toView(UserEntity e) { return new UserDto(e.getId(), e.getName()); }
    @Override protected void mapData(UserEntity e, Map<String, Object> d) { /* whitelist */ }
}
```

**Step 4 — Create the controller:**
```java
@RestController
@RequestMapping("/api/users")
public class UserController extends BaseRestController<UserEntity, UserDto, Long> {
    @Autowired UserService service;
    @Override protected BaseCrudService<UserEntity, UserDto, Long> service() { return service; }
}
```

**Step 5 — Send a search request:**
```bash
curl -X POST http://localhost:8080/api/users/search \
  -H "Content-Type: application/json" \
  -d '{"oper":{"and":["status|=|ACTIVE"]},"pagination":{"page":1,"pageSize":10}}'
```

---

## Key Features

- **Dynamic filtering via 23-operator DSL** — `field|operator|value` condition strings cover
  equality, comparison, LIKE/ILIKE, IN/NOT IN, BETWEEN, NULL checks, EXISTS subqueries, DATE
  comparison, and REGEXP.

- **Logical groups: AND, OR, nested, relation-scoped EXISTS subqueries** — AND and OR groups
  accept nested group objects as children, enabling arbitrary boolean expressions. A top-level
  `oper` key that matches a relation name produces a correlated `EXISTS` subquery.

- **Two-phase pagination strategy: safe with `@OneToMany` and `@ManyToMany`** — when a paginated
  request includes a to-many relation, Phase 1 fetches a page of IDs using a scalar query (no
  collection JOIN), and Phase 2 fetches those entities with their relations by ID. Hibernate's
  in-memory pagination fallback is never triggered.

- **Scalar correlated subquery for to-many orderby** — ordering by a `@ManyToMany` or
  `@OneToMany` field uses a scalar subquery rather than a JOIN, preventing duplicate rows in the
  result set.

- **Strict allowlist model: `@AllowedRelations` + `@AllowedOrderBy` on entities** — unlisted
  paths are rejected at HTTP 400. The allowlist is also configurable programmatically via
  `AllowlistRegistry` for scenarios where annotations on the entity class are not sufficient.

- **Spring Boot auto-configuration with `@ConditionalOnMissingBean` (every bean overridable)** —
  `RestGenericAutoConfiguration` registers all framework beans. Every single registration uses
  `@ConditionalOnMissingBean`. Declare your own bean to replace any part of the pipeline.

- **Optional Blaze-Persistence Entity Views integration** — use `EntityViewSpecificationExecutor`
  for column-level projection without loading full entities. See Chapter 13.

- **Full CRUD + bulk operations** — `create`, `update`, `delete`, `bulkCreate`, `bulkUpdate`,
  `bulkDelete` are all provided by `BaseCrudService`. The `mapData()` extension point allows a
  field whitelist to prevent mass-assignment vulnerabilities.

- **Compatible with Jakarta EE 10 (Spring Boot 3.x) — no `javax`/`jakarta` conflict** — the
  library uses `jakarta.*` throughout. There is no transitive `javax.persistence` dependency.

---

## JSON DSL Quick Reference

```json
{
  "oper": {
    "and": [
      "fieldName|operator|value",
      "anotherField|in|value1,value2,value3",
      {"or": [
        "status|=|ACTIVE",
        "status|=|PENDING"
      ]}
    ],
    "relationName": {
      "and": ["relatedField|=|someValue"]
    }
  },
  "relations": ["relationName", "otherRelation"],
  "orderby": [
    {"fieldName": "asc"},
    {"relationName.fieldName": "desc"}
  ],
  "pagination": {
    "page": 1,
    "pageSize": 20
  }
}
```

**All 23 operators:**

| Category | Operators |
|---|---|
| Equality | `=`, `!=`, `<>` |
| Comparison | `>`, `>=`, `<`, `<=` |
| Pattern | `like`, `not like`, `ilike`, `not ilike` |
| Set | `in`, `not in` |
| Range | `between`, `not between` |
| Null | `null`, `not null` |
| Existence | `exists`, `not exists` |
| Date | `date`, `not date` |
| Regexp | `regexp`, `not regexp` |

---

## Chapter Navigation

| Chapter | File | What You Will Learn |
|---|---|---|
| 01 | `01-introduction-and-architecture.md` | Architecture diagram, design philosophy, layer overview |
| 02 | `02-getting-started.md` | Maven setup, first entity + service + controller in 4 steps |
| 03 | `03-configuration-reference.md` | All `rest-generic.*` properties with defaults and explanations |
| 04 | `04-entity-annotations-and-allowlist.md` | `@AllowedRelations`, `@AllowedOrderBy`, `@FieldsByRole`, `AllowlistRegistry` |
| 05 | `05-dynamic-filtering-dsl.md` | All 23 operators, AND/OR groups, relation-scoped filters, 10 examples |
| 06 | `06-dynamic-ordering.md` | Local vs relational ordering, scalar subquery for to-many, 8 examples |
| 07 | `07-pagination.md` | `Pagination` record, offset math, why `fail_on_pagination` matters |
| 08 | `08-relation-loading.md` | EntityGraph construction, N+1 prevention, 6 examples |
| 09 | `09-building-services.md` | `BaseQueryService` contract, `toView()` strategies (DTO / MapStruct / Blaze) |
| 10 | `10-crud-and-bulk-operations.md` | `create`/`update`/`delete`/bulk, `mapData` override, 5 cURL examples |
| 11 | `11-two-phase-query-strategy.md` | Deep dive: why two-phase, Phase 1 + Phase 2 SQL, performance table |
| 12 | `12-rest-controller-reference.md` | All 10 endpoints, query params, response codes, Spring Security |
| 13 | `13-blaze-persistence-integration.md` | Entity Views setup, `javax`/`jakarta` bridge, fetch strategies |
| 14 | `14-security-best-practices.md` | Allowlist model, mass assignment, SQL injection, multi-tenancy |
| 15 | `15-real-world-scenarios.md` | 8 complete scenarios with JSON + Java + cURL + SQL traces |
| 16 | `16-anti-patterns-and-pitfalls.md` | 12 common mistakes with wrong/correct comparisons |
| 17 | `17-testing-guide.md` | Unit tests, integration tests with H2, SQL assertion, MockMvc |
| 18 | `18-api-reference.md` | Full API surface: all classes, methods, parameters, exceptions |

---

## Compatibility Table

This library and the PHP Laravel `rest-generic-class` share the same DSL contract. The table
below summarizes what is the same and what differs between the two implementations.

| Aspect | Java / Spring Boot | PHP / Laravel |
|---|---|---|
| DSL request format | Identical | Identical |
| Operator symbols (all 23) | Identical | Identical |
| Pagination keys (`page`, `pageSize`) | Identical | Identical |
| Response envelope (`content`, `totalElements`, `totalPages`) | Identical | Identical |
| ORM | Hibernate 6 / JPA | Eloquent |
| Pagination strategy | Two-phase (Hibernate) | Eager load + slice (Eloquent) |
| Relation guard | `@AllowedRelations` annotation | `$allowedRelations` property |
| Filter compilation | JPA `CriteriaBuilder` + `Specification` | Eloquent query scopes |
| Auto-configuration | Spring Boot `@ConditionalOnMissingBean` | Laravel Service Provider |
| Entity Views / projections | Blaze-Persistence (optional) | Eloquent API Resources |
| Minimum runtime | Java 21 | PHP 8.1 |

Client applications — mobile apps, SPAs, CLI tools — that already consume the Laravel API
require no changes when migrating to the Spring Boot backend. The URL paths and HTTP methods
are the responsibility of the server; the DSL body format and response shape are the contract
the library guarantees.

---

## License

See the repository root `LICENSE` file for license terms.
