# Exceptions

## DatabaseErrorParserException

Raised when database errors are parsed into a user-friendly format.

## HttpException

Used throughout the filtering and hierarchy logic to indicate invalid input (unsupported operator, invalid relation, etc.).

## ValidationException (Laravel)

`BaseFormRequest::validate_request()` triggers Laravel's validation exceptions on invalid input.

## RolesContractViolationException

Dedicated exception for the permissions system (3.0.0+). Raised when a model fails to implement the expected contract during resolution of the authenticated user's permissions.

**Namespace.** `Ronu\RestGenericClass\Core\Support\Permissions\Exceptions\RolesContractViolationException`

**Inherits from.** `\RuntimeException`

**Static factories.**

| Factory | When it is thrown |
| --- | --- |
| `::userMissingContract($userOrClass)` | The User model does not implement `ProvidesRoles`. Raised by `UserRolesResolver::rolesOf()` and by the optional validation in `RestGenericClassServiceProvider::boot()`. |
| `::roleMissingContract($roleOrClass)` | A role returned by `provideRoles()` does not implement `ProvidesRolePermissions`. Raised by `UserRolesResolver::rolesOf()`. |

The messages include the exact FQCN of the violating class and the signature of the missing method. See the [permissions guide](../03-usage/06-permissions.md#7-failure-modes-and-diagnostics) for diagnostics.

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
- File: src/Core/Support/Permissions/Exceptions/RolesContractViolationException.php
  - Symbol: RolesContractViolationException::userMissingContract(), ::roleMissingContract()
  - Notes: Dedicated exception for the User/Role permission contract system.
