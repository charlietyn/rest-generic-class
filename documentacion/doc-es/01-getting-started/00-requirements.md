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

### Líneas soportadas de spatie/laravel-permission

| Spatie | Laravel 12 | Laravel 13 | PHP |
| --- | --- | --- | --- |
| 6.21+ | sí | 6.25+ | 8.2+ (8.3+ en Laravel 13) |
| 7.x | sí | 7.2.4+ | 8.3+ (7.0.x y 7.2.4 exigen 8.4) |
| 8.x | sí | sí | 8.3+ |

Composer declara `conflict: spatie/laravel-permission <6.21 || >=9.0`: instalar una línea no probada
falla al resolver dependencias, no en `package:discover`. Spatie sigue siendo opcional: las aplicaciones
que no lo usan no están obligadas a instalarlo. Cada fila se prueba en CI (desde v2.8.1).

**Siguiente:** [Instalación](01-installation.md)

[Volver al índice de documentación](../index.md)
