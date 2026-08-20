# Requisitos

## Requisitos de ejecución

| Laravel | PHP |
| --- | --- |
| 12.61.1+ | 8.2+ |
| 13.12+ | 8.3+ |

Composer fuerza la pareja compatible mediante `laravel/framework: ^12.61.1 || ^13.12.0`.
Las instalaciones con PHP 8.2 resuelven Laravel 12; Laravel 13 requiere PHP 8.3 o superior.

## Integraciones opcionales

Estos paquetes son opcionales y solo se necesitan cuando usas las funciones relacionadas:

- **maatwebsite/excel**: requerido para `exportExcel()`.
- **barryvdh/laravel-dompdf**: requerido para `exportPdf()`.
- **nwidart/laravel-modules**: requerido al usar permisos con módulos.
- **spatie/laravel-permission**: requerido para modelos, traits y middleware de permisos.

**Siguiente:** [Instalación](01-installation.md)

[Volver al índice de documentación](../index.md)
