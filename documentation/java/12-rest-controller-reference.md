# 12. REST Controller Reference

## BaseRestController&lt;E, V, ID&gt;

`BaseRestController` is an abstract generic class that provides 10 pre-built REST endpoints for any entity type. It is the HTTP entry point for the entire `rest-generic-class` library.

**Type parameters:**

| Parameter | Meaning |
|---|---|
| `E` | The JPA entity class (e.g., `UserEntity`) |
| `V` | The view/DTO class returned to clients (e.g., `UserDto`) |
| `ID` | The primary key type (e.g., `Long`, `UUID`) |

**Key design decisions:**

- `BaseRestController` is **not** annotated with `@RestController` or `@RequestMapping`. Subclasses must provide both annotations to define the base path and register the class as a Spring MVC controller.
- The class contains a **single abstract method**: `protected abstract BaseCrudService<E, V, ID> service()`. All 10 endpoints delegate exclusively through this method — the controller contains no business logic.
- This means you can swap the service implementation (e.g., for testing) by changing only the `service()` return value.

---

## The 10 Base Endpoints

| Method | Path | Body / Params | Response | Description |
|---|---|---|---|---|
| GET | `/` | Query params | 200 `Page<V>` | List with optional DSL filter |
| POST | `/search` | `DynamicQueryRequest` JSON | 200 `Page<V>` | Full JSON DSL search |
| GET | `/{id}` | Path variable | 200 `V` / 404 | Single entity by ID |
| GET | `/{id}/with` | Path variable + `?relations=` | 200 `V` / 404 | Single entity with specified relations loaded |
| POST | `/` | `Map<String, Object>` | 201 `V` | Create a single entity |
| PUT | `/{id}` | `Map<String, Object>` | 200 `V` | Update a single entity |
| POST | `/bulk` | `List<Map<String, Object>>` | 201 `List<V>` | Bulk create |
| PUT | `/bulk` | `List<Map<String, Object>>` | 200 `List<V>` | Bulk update |
| DELETE | `/{id}` | Path variable | 204 | Delete a single entity |
| DELETE | `/bulk` | `List<ID>` | 204 | Bulk delete |

---

## GET / — Query-Parameter DSL

The `GET /` endpoint accepts all filtering, ordering, relation-loading, and pagination parameters as URL query parameters. This is convenient for simple reads and browser-accessible URLs.

### Full Parameter Reference

| Parameter | Type | Default | Description |
|---|---|---|---|
| `oper` | URL-encoded JSON string | `null` | The filter expression object |
| `relations` | Comma-separated string or repeated param | `[]` | Relations to load eagerly |
| `orderby` | URL-encoded JSON array string | `[]` | Ordering specification |
| `page` | Integer | `1` | Page number (1-based) |
| `pageSize` | Integer | `20` | Number of results per page |

### `oper` Format

The `oper` parameter is a URL-encoded JSON object using the same filter syntax as the `POST /search` body. Simple conditions use the pipe-delimited format `"field|operator|value"`.

### `relations` Format

Relations can be specified as a comma-separated string or as multiple repeated parameters. Both forms are equivalent:

```
?relations=department,roles
?relations=department&relations=roles
```

### Example URLs

```
# Simple paginated list
GET /users?page=1&pageSize=10

# Filter with URL-encoded JSON
GET /users?oper={"and":["status|=|ACTIVE"]}&orderby=[{"name":"asc"}]

# Load relations, filter, sort
GET /users?relations=department,roles&oper={"and":["status|=|ACTIVE"]}&orderby=[{"name":"asc"}]&page=2&pageSize=25
```

### buildRequestFromParams() Internals

When `GET /` is called, the controller assembles a `DynamicQueryRequest` from the loose query parameters using `buildRequestFromParams()`:

- Parses the `oper` JSON string via `ObjectMapper` into `Map<String, Object>`
- Parses the `orderby` JSON string via `ObjectMapper` into `List<Map<String, String>>`
- Constructs `Pagination.of(page, pageSize)` from the integer parameters
- Returns a `DynamicQueryRequest(oper, relations, orderby, pagination)` — identical in structure to what `POST /search` accepts as a body

Both endpoints therefore execute through exactly the same service-layer path after parameter assembly.

---

## POST /search — The Canonical DSL

`POST /search` accepts the full query DSL as a JSON body. This is the preferred endpoint for complex queries because it avoids URL-length limits, does not require URL encoding of nested structures, and is easier to construct programmatically.

### Full Request Body Structure

```json
{
  "oper": {
    "and": ["status|=|ACTIVE", "age|>=|18"],
    "department": {"and": ["active|=|true"]}
  },
  "relations": ["department", "roles"],
  "orderby": [{"department.name": "asc"}, {"name": "asc"}],
  "pagination": {"page": 1, "pageSize": 20}
}
```

| Field | Type | Description |
|---|---|---|
| `oper` | Object | Filter expression. Top-level keys: `and`, `or`, or a relation name for relation-scoped filters |
| `relations` | Array of strings | Relations to load. Each string is a dot-separated path. Must be declared in `@AllowedRelations`. |
| `orderby` | Array of `{field: direction}` objects | Ordering. Field may be a dot-separated path for relation fields. |
| `pagination` | Object with `page` and `pageSize` | Pagination control. Omit for unpaginated results. |

### Filter Condition Format

Individual conditions within `and`/`or` arrays use the format `"field|operator|value"`:

| Operator | Meaning | Example |
|---|---|---|
| `=` | Equals | `"status\|=\|ACTIVE"` |
| `!=` | Not equals | `"status\|!=\|DELETED"` |
| `>` | Greater than | `"age\|>\|18"` |
| `>=` | Greater than or equal | `"price\|>=\|10.00"` |
| `<` | Less than | `"age\|<\|65"` |
| `<=` | Less than or equal | `"price\|<=\|200.00"` |
| `like` | SQL LIKE | `"name\|like\|John%"` |
| `ilike` | Case-insensitive LIKE | `"email\|ilike\|%@acme.com"` |
| `in` | SQL IN (comma-separated values) | `"status\|in\|ACTIVE,PENDING"` |
| `between` | SQL BETWEEN | `"price\|between\|10.00,200.00"` |
| `null` | IS NULL | `"deletedAt\|null\|"` |
| `not null` | IS NOT NULL | `"deletedAt\|not null\|"` |

---

## GET /{id}/with — Load Relations on a Single Entity

The `GET /{id}/with` endpoint loads a single entity by ID and eagerly fetches the specified relations. This is useful when a detail view needs associated data that is not loaded by default.

```
GET /users/42/with?relations=department,roles
```

Relations specified in the `relations` parameter are validated against `@AllowedRelations` on the entity class. An unrecognised relation name returns HTTP 400.

---

## Bulk Endpoints

### POST /bulk — Bulk Create

Accepts a JSON array of create payloads. Each element is processed by the same `mapData()` path as a single `POST /`. Returns 201 with a list of created views.

```json
POST /users/bulk
[
  {"name": "Alice", "email": "alice@example.com", "status": "ACTIVE"},
  {"name": "Bob",   "email": "bob@example.com",   "status": "ACTIVE"}
]
```

### PUT /bulk — Bulk Update

Accepts a JSON array of update payloads. Each element **must** contain the entity's ID field so the service can locate the existing record. Returns 200 with a list of updated views.

```json
PUT /users/bulk
[
  {"id": 101, "status": "SUSPENDED"},
  {"id": 102, "status": "SUSPENDED"},
  {"id": 103, "status": "ACTIVE"}
]
```

### DELETE /bulk — Bulk Delete

Accepts a JSON array of IDs. Returns 204 with no body. Each entity is loaded and removed individually, so `@PreRemove` lifecycle hooks and cascade rules fire correctly for every record.

```json
DELETE /users/bulk
[101, 102, 103]
```

---

## Exception Handling — GlobalExceptionHandler

The library registers a `@ControllerAdvice` that maps internal exceptions to standardised HTTP error responses.

### HTTP 400 — Client Query Errors

These exceptions indicate a malformed query DSL. The response body follows a consistent shape:

```json
{
  "timestamp": "2026-05-18T10:30:00",
  "status": 400,
  "error": "Bad Request",
  "message": "Relation 'password' is not allowed on UserEntity"
}
```

Exceptions that produce HTTP 400:

| Exception | Cause |
|---|---|
| `InvalidFilterException` | Malformed filter condition syntax |
| `InvalidRelationException` | Relation not in `@AllowedRelations` allowlist |
| `InvalidOperatorException` | Operator not in the configured allowed-operators list |
| `PathDepthExceededException` | Filter path depth exceeds `max-depth` configuration |

### HTTP 422 — Bean Validation Failures

Triggered by Jakarta Bean Validation constraint violations on create/update payloads:

```json
{
  "errors": [
    {"field": "name",  "message": "must not be blank"},
    {"field": "email", "message": "must be a well-formed email address"}
  ]
}
```

### HTTP 404 — Entity Not Found

Returned when `GET /{id}`, `GET /{id}/with`, `PUT /{id}`, or `DELETE /{id}` is called with an ID that does not exist in the database. Spring's default error response structure is used unless you register a custom `@ExceptionHandler`.

### HTTP 500 — Unexpected Errors

Unhandled exceptions fall through to Spring's default error response. Enable `server.error.include-message=always` in development to see the exception message in the response body.

---

## Registering a Controller

Extend `BaseRestController`, add `@RestController` and `@RequestMapping`, and implement the single abstract method:

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

This single class exposes all 10 endpoints at `/api/v1/users`.

---

## Adding Custom Endpoints to a Subclass

You can declare additional endpoints directly on the controller subclass. They coexist with the 10 inherited endpoints:

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

    // Custom endpoint: return the currently authenticated user's own profile
    @GetMapping("/me")
    public ResponseEntity<UserDto> currentUser(@AuthenticationPrincipal UserDetails user) {
        return service().findById(resolveId(user))
                        .map(ResponseEntity::ok)
                        .orElse(ResponseEntity.notFound().build());
    }

    // Custom endpoint: activate a user by ID
    @PostMapping("/{id}/activate")
    public ResponseEntity<UserDto> activate(@PathVariable Long id) {
        return ResponseEntity.ok(service().update(id, Map.of("status", "ACTIVE")));
    }
}
```

Custom endpoints follow standard Spring MVC conventions. They have full access to `service()` and to any additional dependencies injected into the subclass.

---

## Combining with Spring Security @PreAuthorize

You can restrict individual endpoints by overriding them and adding `@PreAuthorize`. The override calls `super` to preserve the base behaviour:

```java
@RestController
@RequestMapping("/api/v1/users")
public class UserController extends BaseRestController<UserEntity, UserDto, Long> {

    @Override
    protected BaseCrudService<UserEntity, UserDto, Long> service() {
        return userService;
    }

    // Anyone with ROLE_USER may read the list
    @GetMapping
    @PreAuthorize("hasRole('ROLE_USER')")
    @Override
    public ResponseEntity<Page<UserDto>> list(
            @RequestParam(required = false) String oper,
            @RequestParam(required = false) List<String> relations,
            @RequestParam(required = false) String orderby,
            @RequestParam(defaultValue = "1") int page,
            @RequestParam(defaultValue = "20") int pageSize) {
        return super.list(oper, relations, orderby, page, pageSize);
    }

    // Only ROLE_ADMIN may create users
    @PostMapping
    @PreAuthorize("hasRole('ROLE_ADMIN')")
    @Override
    public ResponseEntity<UserDto> create(@RequestBody Map<String, Object> body) {
        return super.create(body);
    }

    // Only ROLE_ADMIN may delete
    @DeleteMapping("/{id}")
    @PreAuthorize("hasRole('ROLE_ADMIN')")
    @Override
    public ResponseEntity<Void> delete(@PathVariable Long id) {
        return super.delete(id);
    }
}
```

For method-level security to work, ensure `@EnableMethodSecurity` is present on a `@Configuration` class in your application.

---

## Controller Configuration Reference

```yaml
rest-generic:
  controller:
    default-page-size: 20       # Default pageSize when not specified
    max-page-size: 200          # Hard ceiling on pageSize (prevents accidental full-table dumps)
    default-relations: []       # Relations always loaded, regardless of request

  filtering:
    max-depth: 5                # Maximum nesting depth of filter expressions
    max-conditions: 100         # Maximum total number of conditions per request
    allowed-operators:
      - "="
      - "!="
      - ">"
      - ">="
      - "<"
      - "<="
      - "like"
      - "ilike"
      - "in"
      - "between"
      - "null"
      - "not null"
```

The `max-page-size` limit is enforced in `buildRequestFromParams()` and the `POST /search` deserialiser. A request specifying `pageSize: 10000` will be clamped or rejected with HTTP 400, depending on configuration.
