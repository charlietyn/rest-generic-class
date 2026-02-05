# Excepciones

## DatabaseErrorParserException

Se lanza cuando los errores de base de datos se parsean a un formato amigable.

## HttpException

Se usa en la lógica de filtrado y jerarquía para indicar input inválido (operador no soportado, relación inválida, etc.).

## ValidationException (Laravel)

`BaseFormRequest::validate_request()` dispara las excepciones de validación de Laravel ante input inválido.

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
