# Installation

## Composer
```bash
composer require ronu/rest-generic-class
```

## Auto-discovery del Service Provider
Laravel detecta automáticamente el provider definido en `composer.json` (no requiere registro manual). 

## Publicar configuración (opcional)
```bash
php artisan vendor:publish --tag=rest-generic-class-config
```

## Dependencias opcionales
Algunas funciones requieren paquetes adicionales:
- `maatwebsite/excel` → exportación a Excel (`exportExcel`).
- `barryvdh/laravel-dompdf` → exportación PDF (`exportPdf`).
- `nwidart/laravel-modules` → soporte de módulos en permisos.
- `spatie/laravel-permission` → modelos y permisos.

## Evidence
- File: composer.json
  - Symbol: require/suggest/extra.laravel.providers
  - Notes: dependencias, sugerencias opcionales y auto-discovery.
- File: src/Core/Providers/RestGenericClassServiceProvider.php
  - Symbol: RestGenericClassServiceProvider::boot()
  - Notes: publish tag `rest-generic-class-config`.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::{exportExcel,exportPdf}
  - Notes: funcionalidades que dependen de paquetes sugeridos.
- File: src/Core/Traits/HasPermissionsService.php
  - Symbol: HasPermissionsService
  - Notes: integra `nwidart/laravel-modules` y Spatie permissions.
