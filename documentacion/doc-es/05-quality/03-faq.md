# FAQ

## ¿Este paquete registra rutas por mí?
No. Registras las rutas en tu aplicación Laravel y las conectas con tus controladores que extienden `RestController`.

## ¿Puedo usar MongoDB?
Se incluye una clase `BaseModelMongo` para uso con `mongodb/laravel`. Tú debes integrarla en tu app.

## ¿Spatie permissions es obligatorio?
No. Spatie es opcional. Los modelos, traits y middleware de permisos están disponibles si instalas `spatie/laravel-permission`. Desde 3.0.0, sin embargo, si decides usar el módulo de permisos del paquete, tu modelo `User` está obligado a implementar `ProvidesRoles` y tu modelo `Role` a implementar `ProvidesRolePermissions` (ver [guía de permisos](../03-usage/06-permissions.md)).

## ¿Por qué el contrato `ProvidesRoles` y no una clave de configuración con el nombre de la relación?
Porque una clave de configuración es *stringly-typed*: si escribes mal el nombre, el error aparece en runtime profundo. Una interface es verificada por el cargador de clases de PHP — fail-fast nativo y sin reflection. Además, el contrato permite fuentes no-Eloquent (servicios externos, caché, etc.) sin acoplar la librería al ORM.

## ¿Mis modelos User/Role siguen funcionando si simplemente actualizo a 3.0.0 sin tocar código?
No. La actualización a 3.0.0 es un **breaking change** intencional. Necesitas añadir `implements ProvidesRoles` al User (con su `provideRoles()`) y `implements ProvidesRolePermissions` al Role. La migración total son ~5 líneas de código en dos archivos. Ver [Migración desde 2.x a 3.0.0](../03-usage/06-permissions.md#5-migración-desde-2x-a-300).

## ¿El paquete soporta árboles jerárquicos?
Sí, cuando tu modelo define `const HIERARCHY_FIELD_ID` y envías el parámetro `hierarchy`.

[Volver al índice de documentación](../index.md)
