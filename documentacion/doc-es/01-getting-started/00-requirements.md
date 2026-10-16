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
- **ronu/rest-permissions** (desde 3.0): permisos por rutas — modelos, traits y middleware de permisos, sobre spatie/laravel-permission.

### Líneas soportadas de spatie/laravel-permission (rest-generic-class 2.8.1–2.9; desde 3.0 ver ronu/rest-permissions)

| Spatie | Laravel 12 | Laravel 13 | PHP |
| --- | --- | --- | --- |
| 6.21+ | sí | 6.25+ | 8.2+ (8.3+ en Laravel 13) |
| 7.x | sí | 7.2.4+ | 8.3+ (7.0.x y 7.2.4 exigen 8.4) |
| 8.x | sí | sí | 8.3+ |

En 2.8.1–2.9 Composer declaraba aquí `conflict: spatie/laravel-permission <6.21 || >=9.0`. Desde 3.0 este
paquete no tiene dependencia ni conflicto con Spatie: `ronu/rest-permissions` exige estas líneas y prueba
cada fila en su CI.

**Siguiente:** [Instalación](01-installation.md)

[Volver al índice de documentación](../index.md)
