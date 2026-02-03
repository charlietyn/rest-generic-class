# Routes

El paquete no registra rutas por defecto; debes declarar tus rutas en la app (por ejemplo, `routes/api.php`) apuntando a controllers que extiendan `RestController`.

## Evidence
- File: src/Core/Providers/RestGenericClassServiceProvider.php
  - Symbol: RestGenericClassServiceProvider
  - Notes: no carga archivos de rutas.
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController class
  - Notes: controller base usado en rutas de usuario.
