# Testing

El paquete incluye un harness liviano de PHPUnit para tests unitarios puros. Evita intencionalmente Testbench, fixtures de base de datos Spatie y una app Laravel host.

Ejecuta los tests del paquete desde la raiz del paquete:

```bash
composer install
vendor/bin/phpunit
```

Los tests actuales del paquete cubren:

- Comportamiento wildcard y casos borde de `PermissionCompressor`.
- Payload de permisos autenticado en respuestas planas y comprimidas.

Checks recomendados para tu app host:

- Feature tests para endpoints CRUD usando tus subclases de `RestController`.
- Tests de filtrado `oper` y aplicacion de allowlist de relaciones.
- Tests de listado jerarquico si usas `HIERARCHY_FIELD_ID`.
- Tests de rutas opcionales de permisos si `REST_PERMISSIONS_ROUTES_ENABLED=true`.

[Volver al indice de documentacion](../index.md)
