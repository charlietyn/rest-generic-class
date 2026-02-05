# Testing

Este paquete no incluye tests automatizados. La validación debe realizarse en tu aplicación host.

Checks recomendados para tu app:

- Feature tests para endpoints CRUD usando tus subclases de `RestController`.
- Tests de filtrado `oper` y aplicación de allowlist de relaciones.
- Tests de listado jerárquico si usas `HIERARCHY_FIELD_ID`.

[Volver al índice de documentación](../index.md)
