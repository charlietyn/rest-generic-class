# Advanced usage

## Nested relation filters

Use nested `oper` keys to filter by related models. The package validates relation names against `const RELATIONS`.

```json
{
  "oper": {
    "and": ["status|=|active"],
    "category": {
      "and": ["name|like|%electronics%"]
    }
  }
}
```

## Filter relations when `_nested=true`

When `_nested` is true, relation filters are applied to eager-loaded relations as well as the root query.

```json
{
  "_nested": true,
  "relations": ["category:id,name"],
  "oper": {
    "category": {
      "and": ["name|like|%electronics%"]
    }
  }
}
```

## Sorting by related fields

The `orderby` parameter accepts dot notation to sort by columns of related entities. Each segment before the last is a relation method that **must be declared in the model's `const RELATIONS`** whitelist. The last segment is the column to sort by on the leaf relation.

```json
{
  "orderby": [
    {"user.name": "asc"},
    {"category.parent.name": "desc"},
    {"created_at": "desc"}
  ]
}
```

### How it is translated

Each dotted entry becomes a **scalar ordering subquery** in the SQL — no JOINs are added to the main query. For example, sorting clients by their user's name:

```sql
SELECT clients.*
FROM clients
ORDER BY (
  SELECT users.name
  FROM users
  WHERE users.id = clients.user_id
  LIMIT 1
) ASC
```

For nested paths (`user.role.name`), the subqueries are nested:

```sql
ORDER BY (
  SELECT (
    SELECT roles.name
    FROM roles
    WHERE roles.id = users.role_id
    LIMIT 1
  ) AS value
  FROM users
  WHERE users.id = clients.user_id
  LIMIT 1
) ASC
```

### Why a subquery (and not a JOIN)?

- Works uniformly for `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, `MorphTo`, `MorphOne`, `MorphMany` and `HasManyThrough` — Eloquent's `getRelationExistenceQuery` builds the correct `WHERE` (foreign keys, owner keys, polymorphic type filter, pivot link).
- No duplicate rows for `*-to-many` relations, so `DISTINCT`/`GROUP BY` are not needed.
- Does not collide with `whereHas` filters that may target the same relation.
- No table-alias bookkeeping for self-referencing relations.
- Soft deletes and global scopes of the related model apply automatically.

### Combining sort and filter on the same relation

`oper` (whereHas) and `orderby` (subquery) are independent and compose cleanly:

```json
{
  "oper": {
    "user": { "and": ["name|like|%a%"] }
  },
  "orderby": [
    {"user.name": "desc"}
  ]
}
```

### Local fields are auto-prefixed

When an `orderby` entry has no dot, the column is automatically prefixed with the model's table name. This avoids ambiguity when the query carries a JOIN or `whereHas`-driven subquery referencing a column with the same name in a related table.

### Validation and limits

- Each relation segment is checked against `const RELATIONS` of the corresponding model. Unknown or non-whitelisted segments produce HTTP 400.
- Path depth is bounded by `rest-generic-class.filtering.max_depth` (default `5`), shared with the `oper` engine.
- Direction is normalized: `desc` only when explicitly requested; any other value falls back to `asc`.

### Backward compatibility

If the first segment of a dotted entry is **not** a method on the model, the value is passed to the query builder verbatim. This preserves existing usage where consumers provide a literal `"table.column"` after a manual JOIN.

## Cursor pagination

```json
{
  "pagination": {
    "infinity": true,
    "pageSize": 50,
    "cursor": "eyJpZCI6MTAwfQ=="
  }
}
```

## Hierarchical listing

Enable hierarchy by defining `const HIERARCHY_FIELD_ID` on your model (e.g., `parent_id`).

```json
{
  "hierarchy": {
    "filter_mode": "with_descendants",
    "max_depth": 3,
    "children_key": "children",
    "include_empty_children": true
  }
}
```

The same `hierarchy` parameter can be used on `show()` to return a branch for a single record.

## Export helpers (optional)

`exportExcel()` and `exportPdf()` depend on optional packages. Install them before use:

```bash
composer require maatwebsite/excel barryvdh/laravel-dompdf
```

### Export parameters (junior-friendly guide)

Both export helpers use the **same filtering pipeline** as `list_all()`, so your existing filters, relations, and pagination rules still apply. You can control which columns are exported and, for PDFs, which Blade template is used.

#### Common parameters

| Parameter | Type | Example | What it does |
| --- | --- | --- | --- |
| `select` | `array` or `string` | `["id","name"]` or `"id,name"` | Controls which columns are fetched in the query. |
| `columns` | `array` or `string` | `["name","email"]` or `"name,email"` | Controls which columns are exported. If omitted, it falls back to `select`, or to model `fillable` when `select="*"`. |
| `pagination` | `object` | `{ "page": 1, "pageSize": 50 }` | Keeps your existing pagination behavior. Exports only the requested page unless you use infinite pagination. |
| `filename` | `string` | `"users-2024-10-01.xlsx"` | Overrides the default export filename. |

#### PDF-only parameters

| Parameter | Type | Example | What it does |
| --- | --- | --- | --- |
| `template` | `string` | `"pdf"` or `"reports.users"` | Blade view name used to render the PDF. Defaults to `pdf`. |

#### Example: export Excel (filtered + specific columns)

```json
{
  "select": ["id", "name", "email"],
  "columns": ["name", "email"],
  "oper": { "and": ["active|=|1"] },
  "pagination": { "page": 1, "pageSize": 25 },
  "filename": "active-users.xlsx"
}
```

#### Example: export PDF (filtered + Blade template)

```json
{
  "select": "*",
  "columns": ["name", "email", "created_at"],
  "oper": { "and": ["active|=|1"] },
  "template": "pdf",
  "filename": "active-users.pdf"
}
```

## Validating ID arrays with custom rules

The package ships six validation rules ready to use in any `FormRequest`. All of them operate on arrays of IDs with built-in caching.

### Quick usage in `rules()`

```php
use Ronu\RestGenericClass\Core\Rules\IdsExistInTable;
use Ronu\RestGenericClass\Core\Rules\IdsExistNotDelete;
use Ronu\RestGenericClass\Core\Rules\IdsExistWithAnyStatus;
use Ronu\RestGenericClass\Core\Rules\IdsExistWithDateRange;
use Ronu\RestGenericClass\Core\Rules\IdsWithCustomQuery;
use Ronu\RestGenericClass\Core\Rules\ArrayCount;

public function rules(): array
{
    return [
        // Basic existence
        'role_ids'   => ['required', 'array', new IdsExistInTable('mysql', 'roles')],

        // Excludes soft-deleted records
        'user_ids'   => ['required', 'array', new IdsExistNotDelete('mysql', 'users')],

        // Any of several statuses
        'client_ids' => ['required', 'array',
            new IdsExistWithAnyStatus('mysql', 'clients', ['active', 'trial'])
        ],

        // Within a date range
        'order_ids'  => ['required', 'array',
            new IdsExistWithDateRange(
                'mysql', 'orders', 'created_at',
                now()->subDays(30)->toDateString(),
                now()->toDateString()
            )
        ],

        // Fully custom query
        'slot_ids'   => ['required', 'array',
            new IdsWithCustomQuery('mysql', fn($q) =>
                $q->from('slots')->where('available', true)->where('starts_at', '>', now())
            )
        ],

        // Array count
        'photo_ids'  => ['required', 'array',
            new ArrayCount(min: 1, max: 5, messages: [
                'onMin' => 'Upload at least :min photo.',
                'onMax' => 'Maximum :max photos allowed.',
            ])
        ],
    ];
}
```

### Deferred validation with `addMessageValidator`

When logic depends on the already-built Validator (e.g., cross-field checks), use the deferred hook from `BaseFormRequest`:

```php
use Illuminate\Validation\Validator;

public function rules(): array
{
    return [
        'product_ids'   => ['required', 'array'],
        'product_ids.*' => ['integer'],
        '_check_products' => $this->addMessageValidator(function (Validator $v) {
            $ids     = $this->input('product_ids', []);
            $missing = $this->getMissingIds($ids, 'products', 'id', ['active' => true]);
            if (!empty($missing)) {
                $v->errors()->add('product_ids',
                    'Inactive or non-existent products: ' . implode(', ', $missing));
            }
        }),
    ];
}
```

Full rule and trait reference → [04-reference/05-validation-rules.md](../04-reference/05-validation-rules.md)

[Back to documentation index](../index.md)

## Evidence
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::applyOperTree(), BaseService::relations(), BaseService::list_all(), BaseService::show(), BaseService::listHierarchy(), BaseService::showHierarchy(), BaseService::paginateHierarchyRoots(), BaseService::exportExcel(), BaseService::exportPdf(), BaseService::order_by()
  - Notes: Demonstrates nested filtering, relation loading, hierarchy handling, cursor pagination, export helpers, and relation-aware ordering.
- File: src/Core/Traits/HasDynamicOrderBy.php
  - Symbol: HasDynamicOrderBy::applyDynamicOrderBy(), HasDynamicOrderBy::buildOrderingSubquery()
  - Notes: Implements the dot-notation parser and the scalar ordering subquery generator used by `order_by` and `applyOrdering`.
- File: src/Core/Models/BaseModel.php
  - Symbol: BaseModel::HIERARCHY_FIELD_ID, BaseModel::hasHierarchyField(), BaseModel::RELATIONS
  - Notes: Shows the model contract required to enable hierarchy features and the relation whitelist consulted by ordering and filtering.
