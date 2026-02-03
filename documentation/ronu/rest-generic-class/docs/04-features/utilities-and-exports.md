# Utilities (RequestBody, exports, email)

## Overview
El paquete incluye utilidades para extracción robusta de body (`RequestBody`), exportación básica a Excel/PDF desde `BaseService` y un helper de envío de email. 

## When to use / When NOT to use
**Úsalo cuando:**
- Necesitas acceder al body en formatos mixtos (JSON, form-data, raw).
- Quieres exportar listados a Excel/PDF desde el servicio base.

**No lo uses cuando:**
- Prefieres lógica de exportación completamente custom (plantillas, columnas específicas, etc.).

## How it works
- `RequestBody` intenta parsear el body usando múltiples estrategias y permite `get/all/only/require`.
- `BaseService::exportExcel()` utiliza `Maatwebsite\Excel` para descargar un archivo.
- `BaseService::exportPdf()` utiliza DomPDF y una vista `pdf` (no incluida en el paquete).
- `BaseService::sendEmail()` encapsula un envío simple por `Mail::send`.

## Configuration
- Depende de configuración de `mail` de Laravel.
- Requiere instalar `maatwebsite/excel` o `barryvdh/laravel-dompdf` si usas exportación.

## Usage examples
```php
use Ronu\RestGenericClass\Core\Helpers\RequestBody;

$payload = RequestBody::all($request);
$email = RequestBody::only($request, 'user.email');
```

```php
// En un controller que extiende RestController
public function exportExcel(Request $request)
{
    return $this->service->exportExcel($this->process_request($request));
}
```

## Edge cases / pitfalls
- `exportExcel()` referencia `ModelExport`, que no está definido dentro del repositorio (revisar implementación local).
- `exportPdf()` requiere una vista `pdf` y `barryvdh/laravel-dompdf` instalado.
- `RequestBody::require()` lanza `InvalidArgumentException` si faltan claves.

## Evidence
- File: src/Core/Helpers/RequestBody.php
  - Symbol: RequestBody::{get,all,only,require}
  - Notes: extracción robusta de body.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::{exportExcel,exportPdf,sendEmail}
  - Notes: exportación y envío de email.
- File: composer.json
  - Symbol: suggest
  - Notes: dependencias opcionales para export.
