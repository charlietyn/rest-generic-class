# rest-generic-class — Dynamic Filtering DSL

## Overview

The filtering DSL is the core of every search request. It is a JSON-based, database-agnostic query language that maps directly to JPA Criteria API predicates. The same JSON format is shared with the PHP Laravel implementation, so front-end clients work with both backends unchanged.

All filtering is expressed through the `oper` field of the `DynamicQueryRequest` body, alongside `relations`, `orderby`, and `pagination`.

---

## Request Body Structure

```json
{
  "oper": {
    "and": ["field|operator|value", ...],
    "or":  ["field|operator|value", ...],
    "relationName": {
      "and": ["field|operator|value", ...],
      "or":  ["field|operator|value", ...]
    }
  },
  "relations": ["relationName", "other.nested"],
  "orderby": [{"fieldName": "asc"}, {"other.field": "desc"}],
  "pagination": {"page": 1, "pageSize": 20}
}
```

All four top-level fields are optional. An empty body `{}` returns all records with the default pagination (page 1, pageSize 20).

---

## Condition String Format

Every leaf condition is a pipe-separated string with exactly three parts:

```
field|operator|value
```

| Part       | Rules                                                                                              |
|------------|----------------------------------------------------------------------------------------------------|
| `field`    | JPA attribute name on the entity. Dot-notation is allowed (`department.name`).                     |
| `operator` | One of 23 operators (case-insensitive). See the full table below.                                  |
| `value`    | String literal. Type coercion is applied automatically. Multi-value operators use comma separation. |

**Parsing rule:** The string is split with `split("\\|", 3)` — a limit of 3 — so the value part may itself contain pipe characters.

**Type coercion applied to value strings:**

| Raw string             | Coerced to       |
|------------------------|------------------|
| `"null"` (case-insens.)| Java `null`      |
| `"true"` (case-insens.)| `Boolean.TRUE`   |
| `"false"` (case-insens.)| `Boolean.FALSE` |
| Parseable as `Long`    | `Long`           |
| Parseable as `Double`  | `Double`         |
| Everything else        | `String`         |

Coercion happens in `FilterCompiler.coerce(String raw)` before values are passed to the JPA Criteria builder.

---

## All 23 Operators

The operators are defined in the `FilterOp` enum (`com.ronu.restgeneric.dsl.model.FilterOp`).

### Equality and Comparison

| Symbol | `FilterOp`  | Description                     | Example condition string           |
|--------|-------------|----------------------------------|-------------------------------------|
| `=`    | `EQ`        | Equal                            | `"status|=|ACTIVE"`                |
| `!=`   | `NE`        | Not equal                        | `"status|!=|DELETED"`              |
| `<>`   | `NE_ALT`    | Not equal (SQL alias, same as !=)| `"status|<>|DELETED"`              |
| `>`    | `GT`        | Greater than                     | `"price|>|100"`                    |
| `>=`   | `GE`        | Greater than or equal            | `"age|>=|18"`                      |
| `<`    | `LT`        | Less than                        | `"price|<|500"`                    |
| `<=`   | `LE`        | Less than or equal               | `"stock|<=|0"`                     |

`EQ` and `NE_ALT` both map to `cb.equal()` — they are equivalent. `NE_ALT` is provided for SQL compatibility.

### String Matching

| Symbol      | `FilterOp`   | Description                                       | Example condition string           |
|-------------|--------------|--------------------------------------------------|-------------------------------------|
| `like`      | `LIKE`       | SQL LIKE, applied with `cb.lower()` on both sides| `"name|like|%john%"`               |
| `not like`  | `NOT_LIKE`   | Negated LIKE                                     | `"email|not like|%spam%"`          |
| `ilike`     | `ILIKE`      | Case-insensitive LIKE (explicit; same impl as like)| `"name|ilike|%JOHN%"`           |
| `not ilike` | `NOT_ILIKE`  | Negated case-insensitive LIKE                    | `"email|not ilike|%TEST%"`         |

**Implementation note:** Both `LIKE` and `ILIKE` use `cb.lower((Expression<String>) path)` combined with `raw.toLowerCase()`. This means they are functionally identical in this implementation — both are case-insensitive. If you need a case-sensitive LIKE, override `FilterCompiler` and use `cb.like(path, raw)` directly for the `LIKE` case without the `lower()` wrap.

### Set and Range Operators

| Symbol         | `FilterOp`     | Values format               | Description                          | Example                                    |
|----------------|----------------|-----------------------------|--------------------------------------|--------------------------------------------|
| `in`           | `IN`           | Comma-separated             | IN list                              | `"status|in|ACTIVE,PENDING,REVIEW"`        |
| `not in`       | `NOT_IN`       | Comma-separated             | NOT IN list                          | `"status|not in|DELETED,BANNED"`           |
| `between`      | `BETWEEN`      | Exactly 2 comma-separated   | Inclusive range                      | `"price|between|10.00,99.99"`              |
| `not between`  | `NOT_BETWEEN`  | Exactly 2 comma-separated   | Outside range                        | `"age|not between|18,65"`                  |

For `in`/`not in`, the value string is split on commas into a list: `"ACTIVE,PENDING,REVIEW"` → `["ACTIVE", "PENDING", "REVIEW"]`. Each element is individually type-coerced.

For `between`/`not between`, the split must produce exactly 2 elements. Sending one or three values throws `InvalidFilterException`: _"Operator 'between' requires exactly 2 comma-separated values."_

### Null / Existence Operators

| Symbol        | `FilterOp`   | Description                                      | Value field | Example                             |
|---------------|--------------|--------------------------------------------------|-------------|--------------------------------------|
| `null`        | `NULL`       | IS NULL                                          | Ignored     | `"deletedAt|null|"`                  |
| `not null`    | `NOT_NULL`   | IS NOT NULL                                      | Ignored     | `"deletedAt|not null|"`              |
| `exists`      | `EXISTS`     | IS NOT NULL (alias for `not null`)               | Ignored     | `"profilePicture|exists|"`           |
| `not exists`  | `NOT_EXISTS` | IS NULL (alias for `null`)                       | Ignored     | `"profilePicture|not exists|"`       |

For null-check operators, the value part of the condition string is parsed but ignored by `FilterCompiler`. The pipe must still be present: `"deletedAt|null|"`.

### Date Operators

| Symbol      | `FilterOp`  | Description                                       | Example                                |
|-------------|-------------|---------------------------------------------------|----------------------------------------|
| `date`      | `DATE`      | Compare using database DATE() function            | `"createdAt|date|2026-01-01"`          |
| `not date`  | `NOT_DATE`  | Negated DATE() comparison                         | `"createdAt|not date|2026-01-01"`      |

These operators wrap the field path in `cb.function("DATE", String.class, path)` and compare the result to the string value. Behaviour depends on the database's `DATE()` function. For MySQL: `DATE(created_at) = '2026-01-01'`. For PostgreSQL, consider using `between` with explicit timestamps instead.

### Regular Expression Operators

| Symbol       | `FilterOp`    | Description                    | Example                                     |
|--------------|---------------|--------------------------------|---------------------------------------------|
| `regexp`     | `REGEXP`      | Regex match                    | `"code|regexp|^[A-Z]{3}[0-9]+$"`           |
| `not regexp` | `NOT_REGEXP`  | Negated regex match            | `"email|not regexp|.*@blocked\\.com$"`      |

**Implementation note:** `REGEXP` and `NOT_REGEXP` currently fall back to `cb.like(path, raw)`, which does standard SQL LIKE pattern matching, not regex. This is a known limitation — overriding `FilterCompiler.condition()` with a database-specific native function call (e.g., `cb.function("REGEXP_LIKE", Boolean.class, ...)` for Oracle) is the recommended approach for true regex support. Restrict these operators via `allowed-operators` config if not needed.

---

## Logical Groups

### AND Group

All conditions in an `and` list must be satisfied simultaneously.

```json
{
  "oper": {
    "and": ["status|=|ACTIVE", "age|>=|18", "deletedAt|null|"]
  }
}
```

SQL equivalent: `WHERE status = 'ACTIVE' AND age >= 18 AND deleted_at IS NULL`

### OR Group

At least one condition in an `or` list must be satisfied.

```json
{
  "oper": {
    "or": ["status|=|PENDING", "status|=|REVIEW", "status|=|DRAFT"]
  }
}
```

SQL equivalent: `WHERE status = 'PENDING' OR status = 'REVIEW' OR status = 'DRAFT'`

### Mixed AND + OR in Same Object

When both `and` and `or` are present at the same nesting level, they are combined with AND:

```json
{
  "oper": {
    "and": ["deletedAt|null|"],
    "or":  ["status|=|ACTIVE", "status|=|PENDING"]
  }
}
```

SQL equivalent: `WHERE deleted_at IS NULL AND (status = 'ACTIVE' OR status = 'PENDING')`

### Nested Groups (Object Inside List)

An element of an `and`/`or` list can itself be an object with `and`/`or` keys, enabling arbitrary nesting:

```json
{
  "oper": {
    "and": [
      {"or": ["type|=|PREMIUM", "creditScore|>=|700"]},
      {"and": ["age|>=|21", "country|=|US"]}
    ]
  }
}
```

SQL equivalent:
```sql
WHERE (type = 'PREMIUM' OR credit_score >= 700)
  AND (age >= 21 AND country = 'US')
```

Each nested object increments the depth counter by 1. The maximum nesting depth defaults to 5 (configurable via `rest-generic.filtering.max-depth`).

---

## Relation-Scoped Filters (`whereHas` Equivalent)

Any key in `oper` that is not `"and"` or `"or"` is treated as a relation name. Its value must be an object containing `and`/`or` groups. This is equivalent to Eloquent's `whereHas()` — it filters the root entity based on conditions that apply to a related entity.

```json
{
  "oper": {
    "and": ["status|=|ACTIVE"],
    "department": {
      "and": ["active|=|true", "budget|>=|50000"]
    },
    "roles": {
      "or": ["name|=|ADMIN", "name|=|MANAGER"]
    }
  }
}
```

**How it translates:** For each relation key, `FilterCompiler` builds a correlated `EXISTS` subquery:

```sql
WHERE status = 'ACTIVE'
  AND EXISTS (
    SELECT 1 FROM department d WHERE d.id = u.department_id
      AND d.active = true AND d.budget >= 50000
  )
  AND EXISTS (
    SELECT 1 FROM role r
    INNER JOIN user_roles ur ON ur.role_id = r.id
    WHERE ur.user_id = u.id
      AND (r.name = 'ADMIN' OR r.name = 'MANAGER')
  )
```

The `EXISTS` approach is chosen over a JOIN because:
- A JOIN over a to-many relation inflates the result set (cartesian product), causing incorrect pagination counts.
- `EXISTS` short-circuits as soon as one matching related row is found.

**Relation keys must be in `@AllowedRelations`** on the root entity. An unlisted relation key → HTTP 400 before the subquery is constructed.

---

## Depth Counting

Depth is counted per recursive call in `DslParser.parseOperMap()`:

- Each relation key advances depth by 1 on the recursive call for that key.
- `and` and `or` keys do not advance depth by themselves, but a nested object _inside_ an `and`/`or` list does advance depth when `parseConditionList()` calls `parseOperMap()` with `depth + 1`.

```
{                          ← depth 0
  "and": [                 ← depth 0
    {"or": ["x|=|1"]}     ← depth 1 (nested object inside and-list)
  ],
  "dept": {               ← relation key advances to depth 1
    "and": ["x|=|1"],
    "mgr": {              ← relation key at depth 1 advances to depth 2
      "and": ["y|=|2"]
    }
  }
}
```

With `max-depth: 5`, you can have up to 5 levels of relation nesting.

---

## The `DslParser` in Detail

### Entry Point: `parseOper(Map<String, Object> oper)`

```java
public Optional<FilterNode> parseOper(Map<String, Object> oper) {
    if (oper == null || oper.isEmpty()) {
        return Optional.empty();
    }
    int[] conditionCounter = {0};
    FilterNode root = parseOperMap(oper, 0, conditionCounter);
    return Optional.of(root);
}
```

Returns `Optional.empty()` for null or empty `oper`. Otherwise, initialises the single-element counter array and delegates to `parseOperMap`.

### `parseOperMap(Map, int depth, int[] counter)`

Iterates the keys of the map:
- Key `"and"` → calls `parseConditionList(value, depth, counter)` → adds all results to `andChildren`.
- Key `"or"` → same → adds to `orChildren`.
- Any other key → treats as a relation name, recursively calls `parseOperMap(nestedMap, depth + 1, counter)` → wraps in `RelationFilterNode`.

After processing all keys:
- If only `andChildren` present and size 1 → return the single `ConditionNode` directly (no wrapper `GroupNode`).
- If `andChildren` size > 1 → return `GroupNode(AND, andChildren)`.
- Both `andChildren` and `orChildren` present → wraps each into a `GroupNode` and combines them under a top-level `GroupNode(AND, [andGroup, orGroup])`.
- Relation nodes are appended to the combined list.

### `parseConditionList(Object value, int depth, int[] counter)`

Validates that `value` is a `List<?>`. Iterates elements:
- `String` → calls `parseConditionString(s, counter)` → returns `ConditionNode`.
- `Map<?>` → calls `parseOperMap(nested, depth + 1, counter)` → returns a nested group or relation node.
- Any other type → throws `InvalidFilterException`.

### `parseConditionString(String condition, int[] counter)`

1. Increments `counter[0]`. If it exceeds `maxConditions`, throws `InvalidFilterException`.
2. Splits the string with `split("\\|", 3)`. If the result is not exactly 3 parts, throws.
3. Trims `field`, `operatorSymbol`, `rawValue`.
4. Checks `field` is non-empty.
5. Calls `FilterOp.fromSymbol(operatorSymbol)`. If not found, throws `InvalidOperatorException`.
6. Calls `splitValues(rawValue, op)` to produce `List<String>`.
7. Returns `new ConditionNode(field, op, values)`.

### `splitValues(String raw, FilterOp op)`

- For `IN`, `NOT_IN`: `raw.split(",")` → list of all comma-separated parts.
- For `BETWEEN`, `NOT_BETWEEN`: `raw.split(",")` + assert exactly 2 parts. Throws if not.
- All other operators: returns `List.of(raw)` (single-element list).

### Counter Array Pattern

The `int[] conditionCounter = {0}` is a single-element array used as a mutable integer that can be passed by reference through recursive method calls. Java does not have pass-by-reference for primitives, but arrays are objects. Writing `counter[0]++` inside a recursive call modifies the same array that all callers share, providing a single global count across the entire tree traversal.

### `LOGICAL_KEYS` Set

```java
private static final Set<String> LOGICAL_KEYS = Set.of("and", "or");
```

Although the current code uses `"and".equals(key)` and `"or".equals(key)` directly in the `if` chain, `LOGICAL_KEYS` is declared for documentation and future use in `isRelationKey(key)` checks. Any key not in `LOGICAL_KEYS` is a relation key.

---

## 10 Real-World Filter Examples

All examples use `POST /users/search` unless noted.

---

### Example 1: Active Users in a Specific Department with a Required Role

Find all active, non-deleted users who belong to department 42 and have at least one ADMIN or MANAGER role.

```json
{
  "oper": {
    "and": [
      "status|=|ACTIVE",
      "deletedAt|null|"
    ],
    "department": {
      "and": ["id|=|42"]
    },
    "roles": {
      "or": ["name|=|ADMIN", "name|=|MANAGER"]
    }
  },
  "relations": ["department", "roles"],
  "orderby": [{"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 25}
}
```

Notes:
- Two `and` conditions on the root entity.
- `department` and `roles` are relation-scoped filters (`EXISTS` subqueries).
- Both relations are requested for eager loading via `relations`.
- `roles` is `@ManyToMany` → triggers two-phase pagination.

---

### Example 2: Products in a Price Range with a Category Filter

Find products priced between $10.00 and $99.99 in the "Electronics" category that are not discontinued.

```json
{
  "oper": {
    "and": [
      "price|between|10.00,99.99",
      "discontinued|=|false"
    ],
    "category": {
      "and": ["name|=|Electronics"]
    }
  },
  "orderby": [{"price": "asc"}, {"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 50}
}
```

Notes:
- `"discontinued|=|false"` — the string `"false"` is coerced to `Boolean.FALSE` by `FilterCompiler.coerce()`.
- `"price|between|10.00,99.99"` — split to `["10.00", "99.99"]`, each coerced to `Double`.

---

### Example 3: Orders by Date Range and Status List

Find all orders placed in Q1 2026 with statuses PROCESSING, SHIPPED, or DELIVERED.

```json
{
  "oper": {
    "and": [
      "createdAt|between|2026-01-01,2026-03-31",
      "status|in|PROCESSING,SHIPPED,DELIVERED"
    ]
  },
  "orderby": [{"createdAt": "desc"}],
  "pagination": {"page": 1, "pageSize": 100}
}
```

Notes:
- `between` on a `LocalDate`/`LocalDateTime` field works when the coercion chain produces a comparable value. For strict date comparison, combine with `date` operator or use explicit timestamps.
- `in` splits `"PROCESSING,SHIPPED,DELIVERED"` into three separate string values.

---

### Example 4: Users with a Non-Null Profile Picture

Find users who have uploaded a profile picture and whose account is verified.

```json
{
  "oper": {
    "and": [
      "profilePicture|not null|",
      "emailVerifiedAt|not null|",
      "status|=|ACTIVE"
    ]
  },
  "orderby": [{"createdAt": "desc"}],
  "pagination": {"page": 1, "pageSize": 20}
}
```

Notes:
- `not null` and `exists` are interchangeable. Use whichever reads more naturally in context.
- The value part after the final pipe is empty but the pipe must be present.

---

### Example 5: Employees with Null Salary in Active Departments

Find employees who have not yet had salary set, working in active departments, sorted by department name then employee name.

```json
{
  "oper": {
    "and": ["salary|null|"],
    "department": {
      "and": ["active|=|true"]
    }
  },
  "relations": ["department"],
  "orderby": [{"department.name": "asc"}, {"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 30}
}
```

Notes:
- `"department.name"` in `orderby` requires `"department.name"` to be in `@AllowedOrderBy` on the entity.
- `department` is to-one → `OrderByCompiler` produces a direct `Sort` with a LEFT JOIN.

---

### Example 6: Complex AND(OR, OR) Nested Filter

Find premium users in the US who are either verified by email or verified by phone, and whose account is not suspended.

```json
{
  "oper": {
    "and": [
      "accountType|=|PREMIUM",
      "country|=|US",
      "status|!=|SUSPENDED",
      {"or": ["emailVerifiedAt|not null|", "phoneVerifiedAt|not null|"]}
    ]
  },
  "orderby": [{"createdAt": "desc"}],
  "pagination": {"page": 1, "pageSize": 20}
}
```

Notes:
- A nested object `{"or": [...]}` inside the `and` list is parsed by `parseConditionList` as a nested `parseOperMap` call at `depth + 1`.
- Result AST: `GroupNode(AND, [ConditionNode(EQ, PREMIUM), ConditionNode(EQ, US), ConditionNode(NE, SUSPENDED), GroupNode(OR, [ConditionNode(NOT_NULL), ConditionNode(NOT_NULL)])])`

SQL equivalent:
```sql
WHERE account_type = 'PREMIUM'
  AND country = 'US'
  AND status != 'SUSPENDED'
  AND (email_verified_at IS NOT NULL OR phone_verified_at IS NOT NULL)
```

---

### Example 7: Search by Name Pattern (Case-Insensitive)

Find all products whose name contains "coffee" (any case).

```json
{
  "oper": {
    "or": [
      "name|ilike|%coffee%",
      "description|ilike|%coffee%"
    ]
  },
  "orderby": [{"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 20}
}
```

Notes:
- `ilike` applies `cb.lower()` to both the path and the pattern. Both `name` and `description` fields must be of type `String` in the entity.
- This example uses a top-level `or` group — the entity matches if either field contains the term.

---

### Example 8: Multi-Status IN Filter

Find all invoices that are in any of the "open" statuses.

```json
{
  "oper": {
    "and": [
      "status|in|DRAFT,SENT,OVERDUE,DISPUTED",
      "deletedAt|null|"
    ]
  },
  "orderby": [{"dueDate": "asc"}],
  "pagination": {"page": 1, "pageSize": 50}
}
```

Notes:
- The `in` operator supports any number of comma-separated values.
- Combine with `not in` to exclude known non-relevant statuses: `"status|not in|PAID,CANCELLED,VOID"`.

---

### Example 9: Date Range Filter on `createdAt` Using `date` Operator

Find all records created on a specific calendar date (ignoring the time component).

```json
{
  "oper": {
    "and": ["createdAt|date|2026-03-15"]
  },
  "orderby": [{"createdAt": "asc"}],
  "pagination": {"page": 1, "pageSize": 100}
}
```

Notes:
- Generates `WHERE DATE(created_at) = '2026-03-15'`. This truncates the timestamp to the date part using the database's `DATE()` function.
- For date _ranges_, prefer `between` with full timestamps: `"createdAt|between|2026-03-15T00:00:00,2026-03-15T23:59:59"`.

---

### Example 10: Relation Filter — Users Who Have at Least One Active Role

Find all users who currently have at least one role that is not expired, regardless of which role.

```json
{
  "oper": {
    "and": ["status|=|ACTIVE"],
    "roles": {
      "and": [
        "active|=|true",
        "expiresAt|null|"
      ]
    }
  },
  "relations": ["roles"],
  "orderby": [{"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 20}
}
```

Notes:
- The `roles` key in `oper` generates an `EXISTS` correlated subquery scoped to the `roles` relation.
- The user is included in results only if at least one role has `active = true AND expires_at IS NULL`.
- The `"relations": ["roles"]` field in the same request eagerly loads the roles for the returned users. The filter and the eager load are independent — you can filter on a relation without loading it, or load it without filtering on it.

SQL equivalent:
```sql
SELECT u.*
FROM users u
WHERE u.status = 'ACTIVE'
  AND EXISTS (
    SELECT 1 FROM roles r
    INNER JOIN user_roles ur ON ur.role_id = r.id
    WHERE ur.user_id = u.id
      AND r.active = true
      AND r.expires_at IS NULL
  )
ORDER BY u.name ASC
LIMIT 20 OFFSET 0
```

Since `roles` is `@ManyToMany`, the two-phase strategy is activated when `"relations": ["roles"]` is present with pagination. The actual execution is:

1. **Phase 1:** `SELECT DISTINCT u.id FROM users u WHERE ... ORDER BY name ASC LIMIT 20` → `[1, 3, 7, 9, ...]`
2. **Phase 2:** `SELECT u.* FROM users u WHERE u.id IN (1, 3, 7, 9, ...)` with the roles EntityGraph applied.

---

## `orderby` Field Reference

The `orderby` field is a JSON array where each element is a single-key object mapping a field path to a direction string.

```json
"orderby": [
  {"name": "asc"},
  {"department.name": "desc"},
  {"createdAt": "desc"}
]
```

Rules:
- Direction values: `"asc"` / `"desc"` (case-insensitive). Any unrecognised value defaults to `ASC`.
- Each path must be in `@AllowedOrderBy` on the root entity.
- Dot-notation paths (e.g., `"department.name"`) are valid if explicitly listed in `@AllowedOrderBy`.

---

## `relations` Field Reference

The `relations` field is a JSON array of relation path strings to eager-load for the result set.

```json
"relations": ["department", "roles", "manager.department"]
```

Rules:
- Each path must be in `@AllowedRelations` on the root entity.
- Dot-notation paths load nested relations: `"manager.department"` loads the manager and then the manager's department.
- Loading a to-many relation with pagination triggers the two-phase execution strategy automatically.
- Relations are loaded via JPA EntityGraph in `BaseQueryService.findByIdWithRelations()`. For the main `search()` path, override `fetchEntities()` to apply the graph.

---

## `pagination` Field Reference

```json
"pagination": {"page": 1, "pageSize": 20}
```

| Field      | Type    | Default | Min | Max  | Notes                                         |
|------------|---------|---------|-----|------|-----------------------------------------------|
| `page`     | integer | `1`     | `1` | —    | 1-indexed. Values < 1 are clamped to 1.       |
| `pageSize` | integer | `20`    | `1` | `1000` | Values < 1 clamped to 20; > 1000 clamped to 1000. |

The `Pagination` record provides:
- `offset()` → `(page - 1) * pageSize` — used as `setFirstResult()`.
- `limit()` → `pageSize` — used as `setMaxResults()`.
- `toPageable()` → `PageRequest.of(page - 1, pageSize)` — used to construct `PageImpl`.

---

## Response Format

All search endpoints return a Spring Data `Page<V>`:

```json
{
  "content": [
    { "id": 1, "name": "Alice", "email": "alice@example.com", "status": "ACTIVE" },
    { "id": 2, "name": "Bob",   "email": "bob@example.com",   "status": "ACTIVE" }
  ],
  "totalElements": 42,
  "totalPages": 5,
  "number": 0,
  "size": 10,
  "numberOfElements": 2,
  "first": true,
  "last": false,
  "empty": false,
  "pageable": {
    "pageNumber": 0,
    "pageSize": 10,
    "offset": 0,
    "paged": true,
    "unpaged": false
  },
  "sort": {
    "sorted": true,
    "unsorted": false,
    "empty": false
  }
}
```

Note: `number` is zero-indexed in the Spring Data response, while `pagination.page` in the request is 1-indexed. Page 1 in the request corresponds to `number: 0` in the response.

---

## Error Response Format

All DSL errors return HTTP 400 with a structured body:

```json
{
  "success": false,
  "status": 400,
  "message": "Filter nesting depth exceeded maximum of 5 levels.",
  "timestamp": "2026-03-15T10:30:00.000Z"
}
```

Or for invalid operator:
```json
{
  "success": false,
  "status": 400,
  "message": "Unknown filter operator: 'EQUALS'. Supported operators: =, !=, <>, <, >, <=, >=, like, ...",
  "timestamp": "2026-03-15T10:30:00.000Z"
}
```

Or for relation not in allowlist:
```json
{
  "success": false,
  "status": 400,
  "message": "Relation 'payments' is not allowed on UserEntity. Allowed: department, roles",
  "timestamp": "2026-03-15T10:30:00.000Z"
}
```

All error responses are produced by `GlobalExceptionHandler` (auto-configured as a `@RestControllerAdvice`). Override it by declaring your own bean of the same type.
