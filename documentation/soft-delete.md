# Soft Delete (column-aware) — ronu/rest-generic-class

> **EN first, Español más abajo.**

## What this is

End-to-end, column-driven soft delete for the generated REST stack. A model is
soft-deletable when it declares a soft-delete column; everything else (write
path, reads, restore, force delete, uniqueness, existence rules) follows that
single declaration. Models that do **not** declare a column keep the historic
**physical-delete** behaviour — full backward compatibility.

The mechanism is built on Laravel's native
`Illuminate\Database\Eloquent\SoftDeletes`, made **column-aware**, so you get the
battle-tested scope, events and query helpers for free.

---

## The canonical contract

A soft-deletable model declares the column **and** uses the library trait:

```php
use Ronu\RestGenericClass\Core\Models\BaseModel;
use Ronu\RestGenericClass\Core\Traits\InteractsWithSoftDelete;

class Document extends BaseModel
{
    use InteractsWithSoftDelete;          // ← canonical: brings column-aware SoftDeletes

    protected ?string $softDeleteColumn = 'deleted_at';   // or 'archived_at', ...
}
```

- `protected ?string $softDeleteColumn` — single source of truth. `null` (the
  default on `BaseModel`) means **not** soft-deletable.
- `getSoftDeleteColumn(): ?string` — defined on `BaseModel`, returns the column.
- `isSoftDeletable(): bool` — Laravel's native `Model::isSoftDeletable()`
  (true iff the class uses `SoftDeletes`, which the trait brings in). Call it on
  an instance: `$model->isSoftDeletable()`.

`InteractsWithSoftDelete` simply `use SoftDeletes` and overrides
`getDeletedAtColumn()` to return `getSoftDeleteColumn() ?? 'deleted_at'`. That one
override makes the scope, the delete/restore write-path, the datetime cast and
all `*Trashed()` helpers use the **declared** column.

### Column convention

The column is assumed to be a **`timestamp NULL`** (deleted_at style). The
default convention is `deleted_at`; a custom column such as `archived_at` is fully
supported — everything resolves the model's column, nothing is hardcoded.

> A **boolean flag** column is **not supported** by this mechanism. That would be
> a different strategy and must not be wired through `$softDeleteColumn`.

The soft-delete column must be excluded from `$fillable`/validation (the
generator already does this).

---

## What you get

From the trait (all column-aware):

| Capability | API |
|---|---|
| Soft delete (sets the column) | `$model->delete()` |
| Reads exclude trashed | default query / `Model::all()` |
| Include trashed | `Model::withTrashed()` |
| Only trashed | `Model::onlyTrashed()` |
| Is this row trashed? | `$model->trashed()` |
| Restore | `$model->restore()` |
| Permanent delete | `$model->forceDelete()` |
| Events | `restoring/restored`, `forceDeleting/forceDeleted`, `trashed` |

---

## Service API (`BaseService`)

| Method | Behaviour |
|---|---|
| `destroy($id)` | Soft when the model is soft-deletable, physical otherwise. |
| `destroybyid($ids)` | Same, bulk (`Model::destroy`). |
| `restore($id)` | Resolves **withTrashed** then restores. 422-style error array if the model is not soft-deletable. No-op message if the row is not trashed. |
| `restoreById($ids)` | Bulk restore. |
| `forceDelete($id)` | Permanent delete (resolves withTrashed for soft models). |
| `forceDeleteById($ids)` | Bulk permanent delete. |

Every successful mutation calls `bumpCacheVersion()` to invalidate cache.

---

## Controller / endpoints (`RestController`)

New actions, mirroring the existing `destroy` / `deleteById` response + transaction
pattern:

| Action | Method | Notes |
|---|---|---|
| `restore($id)` | single | id resolved **withTrashed inside the service** — no special route binding needed. |
| `restoreMultiple(Request)` | bulk | accepts `[ids]`, `{ "ids": [...] }`, or the entity-keyed body. |
| `forceDelete($id)` | single | permanent. |
| `forceDeleteMultiple(Request)` | bulk | permanent. |

### Route binding caveat

Because `restore()`/`forceDelete()` resolve the id **withTrashed** *inside the
service*, the controller receives a **raw id** and there is **no 404** on an
already-deleted row. If you instead wire route-model binding, that binding **must**
include trashed rows (`->withTrashed()`), otherwise Laravel 404s before the action
runs. This library does not register generic REST routes (they are generated per
entity), so the generator is responsible for registering:

```
POST   {prefix}/{entity}/{id}/restore        → restore
POST   {prefix}/{entity}/restore             → restoreMultiple
DELETE {prefix}/{entity}/{id}/force          → forceDelete
DELETE {prefix}/{entity}/force               → forceDeleteMultiple
```

---

## Soft-aware validation rules

The generator already adds `->whereNull(<col>)` to the Eloquent `unique` rules.
These vendor rules are the counterpart for the bulk / pivot / existence paths.
All new parameters are **optional and default to the legacy behaviour**.

| Rule / method | New parameter | Effect |
|---|---|---|
| `ValidatesExistenceInDatabase::validateIdsExistNotDeleted()` | `?string $deletedAtColumn = 'deleted_at'` | filter column is parameterized; pass `null` to skip. |
| `IdsExistNotDelete` | `?string $deletedAtColumn = 'deleted_at'` | passes the column through. |
| `IdsExistInTable` | `?string $softDeleteColumn = null` | when set, deleted rows are treated as non-existent. |
| `UniqueCompositeInArray` | `?string $softDeleteColumn = null` | when set, a value freed by a soft delete can be reused. |
| `UniqueInPivot` | `?string $softDeleteColumn`, `?string $pivotSoftDeleteColumn` | excludes soft-deleted main rows and/or pivot memberships. |
| `UniqueInPivotArray` | `?string $softDeleteColumn`, `?string $pivotSoftDeleteColumn` | same, bulk. |

This guarantees a **unique value of a deleted row can be reused** (single,
composite, bulk and pivot), and that existence checks **ignore** deleted rows.

---

## Edge cases

- **Non-soft models**: identical to before (physical delete). Verified by tests.
- **Custom column** (`archived_at`): everything resolves the model's column.
- **Cascade**: soft-deleting a parent does **not** auto-cascade to soft-deletable
  children — **not supported / out of scope**. If you need it, declare it
  explicitly via relations/events to avoid half-alive orphans.
- **Pivots N:N**: pivot uniqueness rules accept a `pivotSoftDeleteColumn` so
  soft-deleted memberships are not counted.
- **Restore uniqueness**: a unique value may have been recreated while a row was
  trashed. Re-validate uniqueness **before** restoring at the application layer if
  your domain requires it (the trait fires `restoring`/`restored` so you can hook
  it).
- **Scope bypass**: `DB::table()` and raw queries bypass the global scope and will
  see trashed rows. Use Eloquent (or add `whereNull(<col>)` manually) on raw paths.
- **Cache**: soft-delete / restore / force-delete all bump the cache version.

---

## Migrating away from `NonDeletedScope`

Previously the generated `App\Models\BaseModel` registered a custom
`App\Scopes\NonDeletedScope`. With this column-aware `SoftDeletes` approach that
scope is **redundant** and should be **retired**: the native `SoftDeletingScope`
(registered by the trait) already excludes trashed rows, column-aware, and adds
the full write-path / restore / force-delete surface. Keeping both only
double-filters reads and gives you no extra behaviour.

---

# Español

## Qué es

Soft delete de extremo a extremo, dirigido **por la columna**, para el stack REST
generado. Un modelo es soft-deletable cuando declara una columna de borrado
lógico; todo lo demás (escritura, lecturas, restore, borrado físico, unicidad,
reglas de existencia) deriva de esa única declaración. Los modelos que **no**
declaran columna mantienen el **borrado físico** histórico — retrocompatibilidad
total.

Se apoya en el trait nativo de Laravel
`Illuminate\Database\Eloquent\SoftDeletes`, hecho **consciente de la columna**.

## Contrato canónico

```php
class Document extends BaseModel
{
    use InteractsWithSoftDelete;                       // canónico
    protected ?string $softDeleteColumn = 'deleted_at'; // o 'archived_at'
}
```

- `$softDeleteColumn` es la **única fuente de verdad**; `null` = no soft-deletable.
- `getSoftDeleteColumn()` está en `BaseModel`.
- `isSoftDeletable()` es el método estático nativo de Laravel (verdadero si la
  clase usa `SoftDeletes`, que el trait aporta). Se puede llamar sobre instancia.

`InteractsWithSoftDelete` hace `use SoftDeletes` y sobreescribe
`getDeletedAtColumn()` → `getSoftDeleteColumn() ?? 'deleted_at'`.

### Convención de columna

La columna es un **`timestamp NULL`** (estilo `deleted_at`). Convención por defecto
`deleted_at`; columna custom como `archived_at` totalmente soportada. **Un flag
booleano NO está soportado** por este mecanismo (sería otra estrategia). La columna
debe quedar fuera de `$fillable`/validación (el generador ya lo hace).

## API de servicio, endpoints y reglas

Idénticos a la sección en inglés: `restore/forceDelete` (single + bulk) en
`BaseService` y `RestController`; reglas con parámetros **opcionales** de columna
(`deletedAtColumn` / `softDeleteColumn` / `pivotSoftDeleteColumn`) que por defecto
mantienen el comportamiento previo. Todas las mutaciones invalidan la versión de
caché.

**Binding de rutas**: `restore`/`forceDelete` resuelven el id **withTrashed dentro
del servicio**, por lo que el controlador recibe un id crudo y **no hay 404**. Si
usas route-model binding, ese binding **debe** incluir borrados (`->withTrashed()`).

## Casos extremos

- Modelos sin soft delete: igual que antes (físico).
- Columna custom: todo usa la columna resuelta.
- Cascada: **no soportada** (documentado). Decláralo explícito si lo necesitas.
- Pivots N:N: usar `pivotSoftDeleteColumn`.
- Restore: revalida unicidad antes de restaurar si tu dominio lo requiere
  (eventos `restoring`/`restored`).
- `DB::table()` / SQL crudo saltan el scope.
- Caché: soft-delete/restore/force-delete invalidan versión.

## Migración desde `NonDeletedScope`

Con este enfoque el `NonDeletedScope` custom es **redundante** y debe **retirarse**:
el `SoftDeletingScope` nativo ya filtra borrados (consciente de la columna) y aporta
restore/forceDelete/withTrashed.
