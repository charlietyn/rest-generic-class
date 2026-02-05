# Middleware

## TransformData

`Ronu\RestGenericClass\Core\Middleware\TransformData` valida solicitudes POST/PUT/PATCH instanciando una clase `BaseFormRequest` y ejecutando `validate_request()`.

## InjectRequestParams

`Ronu\RestGenericClass\Core\Middleware\InjectRequestParams` inyecta pares clave/valor en la request desde parámetros del middleware. Soporta:

- Namespacing (`ns=_meta`).
- Forzar sobreescritura (`force`).
- Casting de tipos (booleanos, números, JSON, base64, null).

## SpatieAuthorize

`Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize` deriva nombres de permisos desde rutas y los valida con el cache de Spatie. Soporta permisos por módulo mediante `nwidart/laravel-modules`.

[Volver al índice de documentación](../index.md)

## Evidencia
- Archivo: src/Core/Middleware/TransformData.php
  - Símbolo: TransformData::handle()
  - Notas: Valida datos de request vía `BaseFormRequest`.
- Archivo: src/Core/Middleware/InjectRequestParams.php
  - Símbolo: InjectRequestParams::handle(), InjectRequestParams::castValue()
  - Notas: Inyecta y casteo de parámetros de request.
- Archivo: src/Core/Middleware/SpatieAuthorize.php
  - Símbolo: SpatieAuthorize::handle()
  - Notas: Deriva permisos y aplica autorización.
