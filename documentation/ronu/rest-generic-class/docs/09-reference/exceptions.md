# Exceptions

## `DatabaseErrorParserException`
Excepción personalizada que encapsula un error de base de datos parseado.

## `HttpException`
Se usa para errores de validación/filtrado y para casos de seguridad (relaciones no permitidas, límites excedidos).

## Evidence
- File: src/Core/Helpers/DatabaseErrorParser.php
  - Symbol: DatabaseErrorParserException
  - Notes: excepción custom.
- File: src/Core/Traits/HasDynamicFilter.php
  - Symbol: HasDynamicFilter::applyFilters()
  - Notes: lanza `HttpException` ante operadores inválidos.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::getRelationsForModel()
  - Notes: `HttpException` ante relaciones no permitidas.
