# Exceptions

## DatabaseErrorParserException

Raised when database errors are parsed into a user-friendly format.

## HttpException

Used throughout the filtering and hierarchy logic to indicate invalid input (unsupported operator, invalid relation, etc.).

## ValidationException (Laravel)

`BaseFormRequest::validate_request()` triggers Laravel's validation exceptions on invalid input.

[Back to documentation index](../index.md)

## Evidence
- File: src/Core/Helpers/DatabaseErrorParser.php
  - Symbol: DatabaseErrorParserException
  - Notes: Defines the custom exception type.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::applyOperTree(), BaseService::normalizeHierarchyParams()
  - Notes: Throws `HttpException` for invalid filters or hierarchy settings.
- File: src/Core/Requests/BaseFormRequest.php
  - Symbol: BaseFormRequest::validate_request()
  - Notes: Uses Laravel validation that throws `ValidationException`.
