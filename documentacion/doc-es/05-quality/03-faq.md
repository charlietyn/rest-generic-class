# FAQ

## ¿Este paquete registra rutas por mí?
No. Registras las rutas en tu aplicación Laravel y las conectas con tus controladores que extienden `RestController`.

## ¿Puedo usar MongoDB?
Se incluye una clase `BaseModelMongo` para uso con `mongodb/laravel`. Tú debes integrarla en tu app.

## ¿Spatie permissions es obligatorio?
No. Spatie es opcional. Los modelos, traits y middleware de permisos están disponibles si instalas `spatie/laravel-permission`.

## ¿El paquete soporta árboles jerárquicos?
Sí, cuando tu modelo define `const HIERARCHY_FIELD_ID` y envías el parámetro `hierarchy`.

[Volver al índice de documentación](../index.md)
