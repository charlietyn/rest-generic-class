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

[Back to documentation index](../index.md)

## Evidence
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::applyOperTree(), BaseService::relations(), BaseService::list_all(), BaseService::show(), BaseService::listHierarchy(), BaseService::showHierarchy(), BaseService::paginateHierarchyRoots(), BaseService::exportExcel(), BaseService::exportPdf()
  - Notes: Demonstrates nested filtering, relation loading, hierarchy handling, cursor pagination, and export helpers.
- File: src/Core/Models/BaseModel.php
  - Symbol: BaseModel::HIERARCHY_FIELD_ID, BaseModel::hasHierarchyField()
  - Notes: Shows the model contract required to enable hierarchy features.
