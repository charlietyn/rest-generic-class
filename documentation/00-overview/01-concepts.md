# Concepts

## Core building blocks

- **BaseModel**: Extends Eloquent with scenario-based validation and relationship allowlists. Use this in your application models.
- **BaseService**: Provides CRUD orchestration, dynamic filtering, relation loading, pagination, and hierarchy support.
- **RestController**: HTTP controller with common CRUD endpoints and helpers to parse REST query parameters.
- **BaseFormRequest**: Validation base class that supports scenario selection and bulk modes.

## Query parameters

The controller parses query and body parameters into a single parameter map. The service uses this map to build the query:

- `select`: Select columns for the main model.
- `relations`: Eager-load relations (with optional field selection like `relation:id,name`).
- `oper`: Structured filtering that supports nested relations and many operators.
- `orderby`: Sorting instructions.
- `pagination`: Offset or cursor pagination (with `infinity`).
- `_nested`: When true, `oper` filters are applied to relations as well as the root query.
- `attr`/`eq`: Legacy equality filters.
- `hierarchy`: Enables hierarchical listing/show when the model defines `HIERARCHY_FIELD_ID`.

## Security posture

The filtering system enforces:

- **Allowlisted relations** via `const RELATIONS` (strict by default).
- **Maximum depth and condition limits** to avoid overly complex queries.
- **Operator allowlist** via configuration.

## Permissions utilities

The package includes middleware and traits that integrate with **spatie/laravel-permission**. These utilities derive permissions from route metadata and help manage role/user permissions when used in your application.

**Next:** [Requirements](../01-getting-started/00-requirements.md)

[Back to documentation index](../index.md)
