# Testing

## Estado actual
En `composer.json` no hay scripts de testing declarados. 

## How to verify (manual)
1. Instala el paquete en una app Laravel 12.
2. Registra un modelo que extienda `BaseModel` y un controller que extienda `RestController`.
3. Ejecuta requests `GET/POST/PUT/DELETE` y valida el comportamiento de filtros.

## Unknown / To confirm
- Ubicación de tests automatizados (si existen fuera del repo actual).
- Configuración de PHPUnit/Pest.

## Evidence
- File: composer.json
  - Symbol: scripts (ausente)
  - Notes: no hay scripts de testing declarados.
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController::index()/store()/update()/destroy()
  - Notes: endpoints base para verificación manual.
