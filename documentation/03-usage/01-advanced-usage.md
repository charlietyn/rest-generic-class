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

[Back to documentation index](../index.md)

## Evidence
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::applyOperTree(), BaseService::relations(), BaseService::list_all(), BaseService::show(), BaseService::listHierarchy(), BaseService::showHierarchy(), BaseService::paginateHierarchyRoots(), BaseService::exportExcel(), BaseService::exportPdf()
  - Notes: Demonstrates nested filtering, relation loading, hierarchy handling, cursor pagination, and export helpers.
- File: src/Core/Models/BaseModel.php
  - Symbol: BaseModel::HIERARCHY_FIELD_ID, BaseModel::hasHierarchyField()
  - Notes: Shows the model contract required to enable hierarchy features.
