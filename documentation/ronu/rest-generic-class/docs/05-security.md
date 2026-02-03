# Security Review

## Superficie de riesgo principal
- **Filtrado dinámico (`oper`)**: requiere validación estricta de relaciones para evitar exposición de datos relacionados no permitidos.
- **Selección de campos**: el `select` permite controlar el payload; no hay allowlist de columnas en el paquete.
- **Middleware de autorización**: `SpatieAuthorize` depende de `spatie/laravel-permission` y guard correcto.
- **Logs**: se registran errores de DB con contexto; revisa datos sensibles en logs.

## Recomendaciones
1. **Mantén `filtering.strict_relations=true`** y define `RELATIONS` en cada modelo expuesto.
2. **Revisa `LOG_LEVEL` y políticas de logging** para evitar filtración de datos en logs.
3. **Valida input con `BaseFormRequest`** y agrega reglas estrictas por escenario.
4. **Asegura guard correcto** en middleware `SpatieAuthorize` (evita permisos por guard equivocado).
5. **Limita exportaciones** (Excel/PDF) solo a usuarios autorizados.

## Pitfalls comunes
- Desactivar `strict_relations` puede exponer relaciones no deseadas (autodetección por reflection).
- Usar `select` sin validación puede exponer columnas sensibles si no se aplica un allowlist propio.
- `exportPdf()` utiliza una vista `pdf` que no se incluye; validar contenido antes de generar PDF.

## Evidence
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::getRelationsForModel()
  - Notes: `strict_relations` y auto-detección de relaciones.
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController::callAction()
  - Notes: logs con `LOG_QUERY`.
- File: src/Core/Middleware/SpatieAuthorize.php
  - Symbol: SpatieAuthorize::handle()
  - Notes: enforcement de permisos y abort(403).
- File: src/Core/Requests/BaseFormRequest.php
  - Symbol: BaseFormRequest::validate_request()
  - Notes: validación de payload.
