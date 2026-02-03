# FAQ

## ¿El paquete registra rutas automáticamente?
No. Debes declarar rutas en tu aplicación y apuntar a controllers que extiendan `RestController`.

## ¿Necesito un Service Provider manual?
No. El provider se auto-descubre vía `composer.json`.

## ¿Cómo controlo las relaciones permitidas?
Declara `const RELATIONS` en cada modelo y mantén `filtering.strict_relations=true`.

## Evidence
- File: composer.json
  - Symbol: extra.laravel.providers
  - Notes: auto-discovery.
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController class
  - Notes: base controller para endpoints.
- File: src/Core/Models/BaseModel.php
  - Symbol: BaseModel::RELATIONS
  - Notes: whitelist de relaciones.
- File: config/rest-generic-class.php
  - Symbol: filtering.strict_relations
  - Notes: enforcement de whitelist.
