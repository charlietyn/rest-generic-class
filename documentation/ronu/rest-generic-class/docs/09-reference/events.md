# Events

No hay Service Provider de eventos ni registro de listeners en `composer.json`. Si necesitas eventos, debes definirlos en tu aplicación huésped.

## Evidence
- File: composer.json
  - Symbol: extra.laravel.providers
  - Notes: sólo registra `RestGenericClassServiceProvider`.
