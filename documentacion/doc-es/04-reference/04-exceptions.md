# Excepciones

## DatabaseErrorParserException

Se lanza cuando los errores de base de datos se parsean a un formato amigable.

## HttpException

Se usa en la lógica de filtrado y jerarquía para indicar input inválido (operador no soportado, relación inválida, etc.).

## ValidationException (Laravel)

`BaseFormRequest::validate_request()` dispara las excepciones de validación de Laravel ante input inválido.

## RolesContractViolationException

Excepción dedicada al sistema de permisos (3.0.0+). Se lanza cuando un modelo no implementa el contrato esperado al resolver permisos del usuario autenticado.

**Namespace.** `Ronu\RestGenericClass\Core\Support\Permissions\Exceptions\RolesContractViolationException`

**Hereda de.** `\RuntimeException`

**Factories estáticas.**

| Factory | Cuándo se lanza |
| --- | --- |
| `::userMissingContract($userOrClass)` | El modelo User no implementa `ProvidesRoles`. Producida por `UserRolesResolver::rolesOf()` y por la validación opcional en `RestGenericClassServiceProvider::boot()`. |
| `::roleMissingContract($roleOrClass)` | Un rol devuelto por `provideRoles()` no implementa `ProvidesRolePermissions`. Producida por `UserRolesResolver::rolesOf()`. |

Los mensajes incluyen el FQCN exacto de la clase violadora y la firma del método que falta. Ver la [guía de permisos](../03-usage/06-permissions.md#7-modos-de-fallo-y-diagnóstico) para diagnóstico.

[Volver al índice de documentación](../index.md)

## Evidencia
- Archivo: src/Core/Helpers/DatabaseErrorParser.php
  - Símbolo: DatabaseErrorParserException
  - Notas: Define el tipo de excepción personalizada.
- Archivo: src/Core/Services/BaseService.php
  - Símbolo: BaseService::applyOperTree(), BaseService::normalizeHierarchyParams()
  - Notas: Lanza `HttpException` para filtros o parámetros de jerarquía inválidos.
- Archivo: src/Core/Requests/BaseFormRequest.php
  - Símbolo: BaseFormRequest::validate_request()
  - Notas: Usa validación de Laravel que lanza `ValidationException`.
- Archivo: src/Core/Support/Permissions/Exceptions/RolesContractViolationException.php
  - Símbolo: RolesContractViolationException::userMissingContract(), ::roleMissingContract()
  - Notas: Excepción dedicada al sistema de contratos de permisos User/Role.
