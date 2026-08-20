# Testing

El paquete usa PHPUnit y Orchestra Testbench. La mayoría de pruebas siguen siendo
unitarias y focalizadas; la prueba de compatibilidad del provider arranca el
paquete dentro de un contenedor real de Laravel.

Ejecuta los tests del paquete desde la raiz del paquete:

```bash
composer install
vendor/bin/phpunit
```

La matriz CI cubre Laravel 12 con PHP 8.2/8.3 y Laravel 13 con PHP 8.3/8.4/8.5.
Ejercita CRUD, relaciones Eloquent, paginación, soft delete, validación,
autorización, permisos, caché y arranque del service provider.

Checks recomendados para tu app host:

- Feature tests para endpoints CRUD usando tus subclases de `RestController`.
- Tests de filtrado `oper` y aplicacion de allowlist de relaciones.
- Tests de listado jerarquico si usas `HIERARCHY_FIELD_ID`.
- Tests de rutas opcionales de permisos si `REST_PERMISSIONS_ROUTES_ENABLED=true`.

[Volver al indice de documentacion](../index.md)
