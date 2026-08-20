# Instalación

Instala el paquete vía Composer:

```bash
composer require ronu/rest-generic-class
```

Laravel registra el service provider automáticamente mediante auto-discovery.

## Actualizar una aplicación de Laravel 12 a Laravel 13

Actualiza la aplicación host a PHP 8.3 o superior y después actualiza Laravel y
este paquete conjuntamente:

```bash
composer require laravel/framework:^13.12 ronu/rest-generic-class --with-all-dependencies
php artisan optimize:clear
```

Si habilitas la caché del paquete, revisa la
[nota sobre caché de objetos en Laravel 13](../02-configuration/03-cache-strategy.md#laravel-13-y-cache-de-objetos-serializados).
El namespace `rgc:v2` impide reutilizar entradas antiguas `rgc:v1`.

Si quieres entender cada cambio, su motivo y el nuevo flujo de caché, consulta
la [guía de migración a Laravel 13 explicada para juniors](03-migracion-laravel-13-para-juniors.md).

## Publicar configuración (opcional)

```bash
php artisan vendor:publish --tag=rest-generic-class-config
```

Esto publica `config/rest-generic-class.php` en tu aplicación.

**Siguiente:** [Inicio rápido](02-quickstart.md)

[Volver al índice de documentación](../index.md)
