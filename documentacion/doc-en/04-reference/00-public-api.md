# Public API

This package exposes classes, traits, and helpers intended for use in your Laravel application.

## Controllers

- `Ronu\RestGenericClass\Core\Controllers\RestController`
  - `index(Request $request)`
  - `getOne(Request $request)`
  - `show(Request $request, $id)`
  - `store(BaseFormRequest $request)`
  - `update(Request $request, $id)`
  - `updateMultiple(Request $request)`
  - `destroy($id)`
  - `deleteById(Request $request)`
  - `export_excel(Request $request)`
  - `export_pdf(Request $request)`

## Services

- `Ronu\RestGenericClass\Core\Services\BaseService`
  - `list_all(array $params)`
  - `get_one(array $params)`
  - `create(array $params)`
  - `update(array $attributes, $id)`
  - `update_multiple(array $params)`
  - `destroy($id)`
  - `destroybyid($id)`
  - `exportExcel(array $params)`
  - `exportPdf(array $params)`
  - Hierarchy helpers: `listHierarchy()`, `showHierarchy()`

## Models

- `Ronu\RestGenericClass\Core\Models\BaseModel`
  - Defines `MODEL`, `RELATIONS`, `PARENT`, and `HIERARCHY_FIELD_ID` constants.
  - Validation helpers: `self_validate()`, `validate_all()`.
  - Hierarchy helpers: `hierarchyParent()`, `hierarchyChildren()`.
- `Ronu\RestGenericClass\Core\Models\BaseModelMongo`
  - MongoDB equivalent with validation helpers.
- `Ronu\RestGenericClass\Core\Models\SpatieRole`
- `Ronu\RestGenericClass\Core\Models\SpatiePermission`

## Requests

- `Ronu\RestGenericClass\Core\Requests\BaseFormRequest`
  - Scenario selection with `_scenario` and bulk detection.
  - `validate_request()` helper for middleware usage.
  - `addMessageValidator(Closure)` — deferred validation callback after Validator is built.
  - `validateIdsWithRelation()` — validate IDs with parent FK constraint.
  - `validateUnique()` — uniqueness excluding the current record.
  - `getMissingIdsMessage()` — formatted error string with missing IDs.
  - `validatedWith(array)` — merge validated data with additional computed data.
  - `isCreating()` / `isUpdating()` — semantic request type helpers.
  - `getWithDefault()` — input value with fallback.

## Traits

- `HasDynamicFilter` (query filtering)
- `HandlesQueryExceptions`
- `HasPermissionsController` / `HasPermissionsService`
- `HasReadableUserPermissions` / `HasReadableRolePermissions`
- `ValidatesExistenceInDatabase` — database ID array validation with built-in caching
  - `validateIdsExistInTable()`, `validateIdsExistWithStatus()`, `validateIdsExistNotDeleted()`
  - `validateIdsExistWithAnyStatus()`, `validateIdsExistWithDateRange()`, `validateIdsWithCustomQuery()`
  - `getMissingIds()`, `clearValidationCache()`

## Validation Rules

- `Ronu\RestGenericClass\Core\Rules\IdsExistInTable` — ID existence in an arbitrary table and column
- `Ronu\RestGenericClass\Core\Rules\IdsExistNotDelete` — same as above but excludes soft-deleted records
- `Ronu\RestGenericClass\Core\Rules\IdsExistWithAnyStatus` — IDs with any of the specified statuses (OR)
- `Ronu\RestGenericClass\Core\Rules\IdsExistWithDateRange` — IDs within a date range
- `Ronu\RestGenericClass\Core\Rules\IdsWithCustomQuery` — IDs validated with a custom `Closure` query
- `Ronu\RestGenericClass\Core\Rules\ArrayCount` — array element count with configurable messages

Full reference → [05-validation-rules.md](./05-validation-rules.md)

## Helpers

- `RequestBody` for reading body parameters across HTTP methods.
- `DatabaseErrorParser` for translating DB errors.
- `HelpersValidations` for array-based unique validation.

[Back to documentation index](../index.md)

## Evidence
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController class and CRUD methods
  - Notes: Defines the controller surface for REST operations.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService class and CRUD/export/hierarchy methods
  - Notes: Defines the service-level API.
- File: src/Core/Models/BaseModel.php
  - Symbol: BaseModel constants and validation helpers
  - Notes: Defines model contracts and validation/hierarchy helpers.
- File: src/Core/Models/BaseModelMongo.php
  - Symbol: BaseModelMongo
  - Notes: MongoDB model base class.
- File: src/Core/Requests/BaseFormRequest.php
  - Symbol: BaseFormRequest::getScenario(), BaseFormRequest::validate_request(), BaseFormRequest::addMessageValidator(), BaseFormRequest::validatedWith()
  - Notes: Request validation surface with advanced helpers and deferred validation.
- File: src/Core/Traits/HasDynamicFilter.php
  - Symbol: HasDynamicFilter::scopeWithFilters()
  - Notes: Filter trait used by BaseService.
- File: src/Core/Traits/ValidatesExistenceInDatabase.php
  - Symbol: ValidatesExistenceInDatabase
  - Notes: Base trait for ID validation against the database with caching.
- File: src/Core/Rules/
  - Symbol: IdsExistInTable, IdsExistNotDelete, IdsExistWithAnyStatus, IdsExistWithDateRange, IdsWithCustomQuery, ArrayCount
  - Notes: Six custom validation rules for ID arrays and array count.
- File: src/Core/Helpers/RequestBody.php
  - Symbol: RequestBody::get(), RequestBody::all()
  - Notes: Request body helper interface.
