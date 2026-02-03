# Middleware reference

## `TransformData`
- Valida requests `POST/PUT/PATCH` usando un `FormRequest` (pasado como parámetro del middleware).

## `InjectRequestParams`
- Inyecta parámetros en el request (`ns=`, `force`) y castea valores (`json:`/`b64:`).

## `SpatieAuthorize`
- Deriva permisos a partir de rutas/controladores y hace `abort(403)` si el usuario no está autorizado.

## Evidence
- File: src/Core/Middleware/TransformData.php
  - Symbol: TransformData::handle()
  - Notes: validación previa al controller.
- File: src/Core/Middleware/InjectRequestParams.php
  - Symbol: InjectRequestParams::handle()
  - Notes: inyección y casteo de params.
- File: src/Core/Middleware/SpatieAuthorize.php
  - Symbol: SpatieAuthorize::handle()
  - Notes: autorización por permisos.
