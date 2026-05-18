# Chapter 10: CRUD and Bulk Operations

`BaseCrudService<E, V, ID>` exposes six transactional write methods covering single-entity and bulk create, update, and delete operations. This chapter details the execution flow of each method, explains the role of `mapData()` in preventing mass-assignment vulnerabilities, and provides complete curl examples for every operation.

---

## `create(Map<String, Object> data)` — Single Create

### Execution Flow

1. **`newInstance()`** — reflection: `entityClass().getDeclaredConstructor().newInstance()`. Creates a bare entity instance with all fields at their default values.
2. **`mapData(entity, data)`** — applies the request payload to the entity. See the `mapData` section for default behavior and override guidance.
3. **`em.persist(entity)`** — registers the entity with the persistence context. No SQL is issued yet.
4. **`em.flush()`** — forces the INSERT to be sent to the database. The DB generates any auto-increment ID, triggers, and column defaults.
5. **`em.refresh(entity)`** — reloads the entity from the database. Picks up the generated primary key, DB-default column values (e.g., `created_at DEFAULT NOW()`), and any values set by database triggers.
6. **`toView(entity)`** — maps the fully populated entity to the view type and returns it.

The explicit `flush()` + `refresh()` sequence ensures the returned view contains the DB-assigned ID and all server-side defaults — the caller never receives a partially populated object.

### HTTP Response

- Status: **201 Created**
- Body: the fully populated view object `V`

---

## How Default `mapData()` Reflection Works

When you do not override `mapData()`, the default implementation walks the entity's class hierarchy and sets fields by name using reflection:

```java
protected void mapData(E entity, Map<String, Object> data) {
    data.forEach((key, value) -> {
        var field = findField(entity.getClass(), key);
        if (field != null) {
            field.setAccessible(true);
            try {
                field.set(entity, value);
            } catch (IllegalAccessException e) {
                throw new RuntimeException(e);
            }
        }
    });
}

// findField walks up the class hierarchy via getSuperclass()
private Field findField(Class<?> clazz, String name) {
    if (clazz == null) return null;
    try {
        return clazz.getDeclaredField(name);
    } catch (NoSuchFieldException e) {
        return findField(clazz.getSuperclass(), name);
    }
}
```

**Mass assignment risk:** the default applies **all** keys present in the data map, including audit fields (`createdAt`, `createdBy`, `version`) and any other field on the entity. A request body containing `{"price": 0.01, "createdBy": "attacker"}` would write both fields.

**Always override `mapData()` in production services** to accept only the fields that clients are permitted to set.

---

## `update(ID id, Map<String, Object> data)` — Single Update

### Execution Flow

1. **`em.find(entityClass(), id)`** — loads the entity by primary key within the current transaction. Returns `null` if not found → propagates as `EntityNotFoundException` (callers should handle this in the controller layer if a custom 404 response is needed).
2. **`mapData(entity, data)`** — partial update: only the keys present in `data` are applied. Keys absent from the map are untouched on the entity.
3. **`em.flush()`** — Hibernate detects dirty fields and issues an UPDATE for only the changed columns.
4. **`toView(entity)`** — maps and returns the updated entity.

No explicit `em.refresh()` is needed after update because the entity is already in the persistence context with up-to-date values after `flush()`.

### HTTP Response

- Status: **200 OK**
- Body: the updated view object `V`

---

## `bulkCreate(List<Map<String, Object>> items)` — Bulk Create

Iterates over `items` and calls `create()` for each one, all within the same transaction.

```java
public List<V> bulkCreate(List<Map<String, Object>> items) {
    return items.stream()
                .map(this::create)
                .collect(Collectors.toList());
}
```

**Batching:** for large lists, set `hibernate.jdbc.batch_size` in `application.properties` to enable JDBC batching:

```properties
spring.jpa.properties.hibernate.jdbc.batch_size=50
spring.jpa.properties.hibernate.order_inserts=true
```

With batching enabled, Hibernate accumulates INSERT statements and sends them to the database in groups of 50, reducing round-trips from N to ceil(N/50).

**Transaction semantics:** any failure in any item rolls back the entire transaction. No partial creates are committed.

### HTTP Response

- Status: **201 Created**
- Body: `List<V>` — all created view objects in insertion order

---

## `bulkUpdate(List<Map<String, Object>> items)` — Bulk Update

Each item map **must** contain an `"id"` key. The method extracts the ID, then calls `update(id, item)` for each entry.

```java
public List<V> bulkUpdate(List<Map<String, Object>> items) {
    return items.stream().map(item -> {
        if (!item.containsKey("id"))
            throw new IllegalArgumentException("Bulk update item missing 'id' key");
        @SuppressWarnings("unchecked")
        ID id = (ID) item.get("id");
        return update(id, item);
    }).collect(Collectors.toList());
}
```

If any item is missing `"id"`, an `IllegalArgumentException` is thrown and the transaction rolls back. If any item's entity is not found (`EntityNotFoundException`), the same rollback applies.

**Transaction semantics:** all-or-nothing. Either every update succeeds or none are persisted.

### HTTP Response

- Status: **200 OK**
- Body: `List<V>` — all updated view objects in the order of the request list

---

## `delete(ID id)` — Single Delete

### Execution Flow

1. **`em.find(entityClass(), id)`** — loads the entity. Throws `EntityNotFoundException` if not found.
2. **`em.remove(entity)`** — marks the entity for deletion. Hibernate fires:
   - `@PreRemove` lifecycle callback on the entity (if declared)
   - Cascaded removes on any `cascade = CascadeType.REMOVE` associations
   - A DELETE statement on `em.flush()` (at transaction commit or next flush point)

**Why `em.find()` before `em.remove()` (rather than a DELETE by ID query):**

```java
// Approach A — loads entity first (used by the library)
E entity = em.find(entityClass(), id);
em.remove(entity);
// Result: @PreRemove fires, cascades work, orphan removal applies

// Approach B — DELETE query (bypasses the persistence context)
em.createQuery("DELETE FROM UserEntity u WHERE u.id = :id")
  .setParameter("id", id)
  .executeUpdate();
// Result: @PreRemove does NOT fire, cascades do NOT work, orphan removal does NOT apply
```

Loading first ensures all JPA cascade and lifecycle rules execute correctly.

### HTTP Response

- Status: **204 No Content**
- Body: empty

---

## `bulkDelete(List<ID> ids)` — Bulk Delete

Iterates and calls `delete(id)` for each ID in the same transaction:

```java
public void bulkDelete(List<ID> ids) {
    ids.forEach(this::delete);
}
```

If any ID does not exist, `EntityNotFoundException` is thrown and the transaction rolls back. All or nothing.

### HTTP Response

- Status: **204 No Content**
- Body: empty

---

## `@Transactional` Semantics

All six write methods are annotated with `@Transactional`:

```java
@Transactional
public V create(Map<String, Object> data) { ... }

@Transactional
public V update(ID id, Map<String, Object> data) { ... }
// ... etc.
```

Spring creates a CGLIB proxy around `BaseCrudService` subclasses. When a write method is called:

1. Spring checks whether a transaction is already active on the current thread (default propagation: `REQUIRED`).
2. If no transaction exists, Spring begins a new one.
3. If a transaction already exists (e.g., the caller is also `@Transactional`), the method joins it.
4. On successful return, the transaction is committed (if Spring created it).
5. On any unchecked exception (`RuntimeException` or `Error`), the transaction is rolled back.

The explicit `em.flush()` inside `create()` forces the INSERT within the open transaction rather than deferring it to commit time, which is necessary to trigger ID generation before `em.refresh()`.

---

## Overriding `mapData()` with MapStruct

MapStruct's `@MappingTarget` annotation generates update methods that apply a source map (or DTO) to an existing entity:

```java
@Mapper(componentModel = "spring")
public interface UserMapper {
    @BeanMapping(nullValuePropertyMappingStrategy = NullValuePropertyMappingStrategy.IGNORE)
    void updateFromDto(UserUpdateDto dto, @MappingTarget UserEntity entity);
}
```

```java
@Override
protected void mapData(UserEntity entity, Map<String, Object> data) {
    // Convert Map to DTO first, then delegate to MapStruct
    UserUpdateDto dto = objectMapper.convertValue(data, UserUpdateDto.class);
    userMapper.updateFromDto(dto, entity);
}
```

`NullValuePropertyMappingStrategy.IGNORE` ensures that null values in the source do not overwrite non-null values on the target — the correct behavior for partial PATCH-style updates.

---

## Handling Nested Entity References (Set by ID)

When a client sends a foreign key ID rather than an embedded object (common pattern: `"departmentId": 10`), use `em.getReference()` to avoid loading the referenced entity:

```java
@Override
protected void mapData(UserEntity entity, Map<String, Object> data) {
    if (data.containsKey("name"))
        entity.setName((String) data.get("name"));

    if (data.containsKey("email"))
        entity.setEmail((String) data.get("email"));

    if (data.containsKey("departmentId")) {
        Long deptId = ((Number) data.get("departmentId")).longValue();
        // getReference() returns a proxy without hitting the DB.
        // Hibernate only resolves the proxy to a real SELECT if you access
        // any non-ID field on the returned proxy.
        entity.setDepartment(em.getReference(DepartmentEntity.class, deptId));
    }

    if (data.containsKey("managerId")) {
        Long managerId = ((Number) data.get("managerId")).longValue();
        entity.setManager(em.getReference(UserEntity.class, managerId));
    }
}
```

If the referenced ID does not exist in the database, `em.getReference()` does not fail immediately. The constraint violation is raised at `flush()` time as a `jakarta.persistence.EntityNotFoundException` or a DB foreign key constraint error.

---

## Complete CRUD Examples

### Example 1 — Create a User

```bash
curl -X POST http://localhost:8080/users \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Alice Johnson",
    "email": "alice@example.com",
    "status": "ACTIVE",
    "departmentId": 10
  }'
```

**Request JSON:**

```json
{
  "name": "Alice Johnson",
  "email": "alice@example.com",
  "status": "ACTIVE",
  "departmentId": 10
}
```

**Response — 201 Created:**

```json
{
  "id": 42,
  "name": "Alice Johnson",
  "email": "alice@example.com",
  "status": "ACTIVE",
  "departmentId": 10,
  "createdAt": "2026-05-18T09:15:00Z"
}
```

The `id` and `createdAt` are populated by `em.refresh()` after the DB-assigned values are generated.

---

### Example 2 — Update a User

```bash
curl -X PUT http://localhost:8080/users/42 \
  -H "Content-Type: application/json" \
  -d '{
    "email": "alice.johnson@example.com",
    "status": "INACTIVE"
  }'
```

**Request JSON:**

```json
{
  "email": "alice.johnson@example.com",
  "status": "INACTIVE"
}
```

**Response — 200 OK:**

```json
{
  "id": 42,
  "name": "Alice Johnson",
  "email": "alice.johnson@example.com",
  "status": "INACTIVE",
  "departmentId": 10,
  "createdAt": "2026-05-18T09:15:00Z"
}
```

Only `email` and `status` changed. `name`, `departmentId`, and `createdAt` are untouched because they were absent from the request body.

---

### Example 3 — Bulk Create 3 Products

```bash
curl -X POST http://localhost:8080/products/bulk \
  -H "Content-Type: application/json" \
  -d '[
    {"name": "Widget A", "price": 9.99,  "status": "ACTIVE"},
    {"name": "Widget B", "price": 14.99, "status": "ACTIVE"},
    {"name": "Widget C", "price": 4.49,  "status": "DRAFT"}
  ]'
```

**Request JSON:**

```json
[
  {"name": "Widget A", "price": 9.99,  "status": "ACTIVE"},
  {"name": "Widget B", "price": 14.99, "status": "ACTIVE"},
  {"name": "Widget C", "price": 4.49,  "status": "DRAFT"}
]
```

**Response — 201 Created:**

```json
[
  {"id": 101, "name": "Widget A", "price": 9.99,  "status": "ACTIVE"},
  {"id": 102, "name": "Widget B", "price": 14.99, "status": "ACTIVE"},
  {"id": 103, "name": "Widget C", "price": 4.49,  "status": "DRAFT"}
]
```

All three are created in a single transaction. If Widget B failed validation, none would be persisted.

---

### Example 4 — Bulk Update 2 Orders

```bash
curl -X PUT http://localhost:8080/orders/bulk \
  -H "Content-Type: application/json" \
  -d '[
    {"id": 55, "status": "SHIPPED",   "trackingNumber": "TRK-001"},
    {"id": 56, "status": "DELIVERED", "trackingNumber": "TRK-002"}
  ]'
```

**Request JSON:**

```json
[
  {"id": 55, "status": "SHIPPED",   "trackingNumber": "TRK-001"},
  {"id": 56, "status": "DELIVERED", "trackingNumber": "TRK-002"}
]
```

**Response — 200 OK:**

```json
[
  {"id": 55, "customerId": 7, "status": "SHIPPED",   "trackingNumber": "TRK-001", "total": 149.00},
  {"id": 56, "customerId": 9, "status": "DELIVERED", "trackingNumber": "TRK-002", "total": 89.50}
]
```

Both orders are updated atomically. The `"id"` field in each item is required — omitting it causes an `IllegalArgumentException` and a 500 error (or 400 if caught in the controller).

---

### Example 5 — Delete with Cascade

```bash
curl -X DELETE http://localhost:8080/users/42
```

**No request body.**

**Response — 204 No Content:**

```
HTTP/1.1 204 No Content
```

If `UserEntity` has `@OneToMany(cascade = CascadeType.REMOVE)` on an `addresses` collection, Hibernate deletes all associated addresses in the same transaction before deleting the user. The `@PreRemove` lifecycle callback fires if declared:

```java
@Entity
public class UserEntity {

    @PreRemove
    void onRemove() {
        // e.g., log audit event, clean up external resources
    }

    @OneToMany(mappedBy = "user", cascade = CascadeType.REMOVE, orphanRemoval = true)
    private List<AddressEntity> addresses;
}
```

---

## Response Shape Summary

| Operation | Method | HTTP Status | Response Body |
|-----------|--------|-------------|---------------|
| Single create | `POST /` | 201 Created | `V` (single view object) |
| Single update | `PUT /{id}` | 200 OK | `V` (updated view object) |
| Bulk create | `POST /bulk` | 201 Created | `List<V>` |
| Bulk update | `PUT /bulk` | 200 OK | `List<V>` |
| Single delete | `DELETE /{id}` | 204 No Content | (empty) |
| Bulk delete | `DELETE /bulk` | 204 No Content | (empty) |

The `BaseRestController<E, V, ID>` maps each service method to its endpoint and sets the correct HTTP status code. Create operations use `ResponseEntity.status(HttpStatus.CREATED)`, deletes use `ResponseEntity.noContent()`, and updates use `ResponseEntity.ok()`.
