# Plan de migración y auditoría para Laravel 13

Para una explicación pedagógica de cada cambio, sus motivos, ejemplos de caché,
glosario y pasos de adopción, consulta la
[guía de migración a Laravel 13 para juniors](../documentacion/doc-es/01-getting-started/03-migracion-laravel-13-para-juniors.md).

## Objetivo y alcance

Mantener compatibilidad con versiones parcheadas de Laravel 12 y 13 sin romper la API
pública del paquete. La combinación soportada es:

| Laravel | PHP | PHPUnit | Testbench |
| --- | --- | --- | --- |
| 12.61.1+ | 8.2+ | 11.5+ | 10.9+ |
| 13.12+ | 8.3+ | 12.5+ | 11.x |

La migración toma como referencia el proyecto local
`D:\Programacion\Programacion\Tech\Backend\Laravel\13\laravel`, su salida
`graphify-out`, la guía oficial de actualización 12→13 y el código completo del
paquete. El `graphify-out` local solo contiene un índice estadístico del
esqueleto; no contiene el grafo interno de clases de `laravel/framework`. Por
ello, las firmas se validan contra las dependencias reales resueltas por Composer
y mediante Testbench.

## Revisiones realizadas

### Revisión 1: inventario y línea base

- Estado inicial limpio en `main`, tag 2.3.3, Laravel 12.51 y PHPUnit 9.6.
- Inventario completo de `src`, `tests`, configuración, rutas y documentación EN/ES.
- Búsqueda global de imports Illuminate, helpers, extensiones de contratos,
  paginación, Eloquent, caché, routing, colas y APIs afectadas por Laravel 13.
- Línea base: 146 tests y 389 assertions correctos con PHP 8.2 y PHP 8.3 sobre
  Laravel 12.51.

### Revisión 2: cruce con cambios de Laravel 13

| Cambio oficial | Presencia | Decisión |
| --- | --- | --- |
| PHP mínimo 8.3 en Laravel 13 | Sí, metadata | Mantener PHP 8.2 para Laravel 12; Composer fuerza 8.3 al resolver Laravel 13. |
| Dependencias Laravel/PHPUnit | Sí | Constraints duales y matriz CI explícita. |
| Advisories en 12.51 y 13.0–13.11 | Sí, durante el gate mínimo | Elevar mínimos a 12.61.1 y 13.12.0; `composer audit` queda como gate CI. |
| `cache.serializable_classes=false` | Sí, paginadores cacheados | Respetar el hardening; no guardar objetos si Laravel los prohíbe y documentar allowlist. |
| Prefijos de caché nuevos | Indirecta | Namespace propio elevado de `rgc:v1` a `rgc:v2`. |
| Contrato cache `touch` | No | El paquete consume stores; no implementa stores propios. |
| `Container::call` con nullable | No | No se usa `Container::call`. |
| Nuevos métodos de contratos framework | No | No hay implementaciones propias de Bus, Queue, ResponseFactory o MustVerifyEmail. |
| `upsert` con `uniqueBy` vacío | No | No existen llamadas `upsert`. |
| `DELETE JOIN ORDER/LIMIT` | No directa | Los deletes del paquete no construyen esa combinación MySQL. |
| Instanciación de modelos durante `boot` | No | El provider no instancia modelos; los inicializadores de traits no anidan boot. |
| Nombre de pivot polimórfico | No directa | No hay custom `MorphPivot`; Spatie recibe tablas explícitas desde config. |
| Serialización de colecciones Eloquent | No en colas | Solo afecta el camino de caché ya mitigado. |
| Firmas de HTTP client response | No | No se extienden responses del cliente HTTP. |
| Eventos de notificaciones/colas | No | No hay listeners para `JobAttempted` o `QueueBusy`. |
| Precedencia de rutas por dominio | No | Las rutas opcionales no declaran dominios. |
| Serialización de sesión | No | El paquete no publica ni modifica config de sesión. |
| Timing de scheduling | No | No registra tareas programadas. |
| `PreventRequestForgery` | No | No referencia aliases CSRF antiguos. |
| Binding de callbacks `Manager::extend` | No | La documentación usa `Container::extend`, que no es el API afectado. |
| Reset de factories `Str` | No | No instala factories globales de strings. |
| `Js::from` unicode | No | No usa `Js::from`. |
| `array_first` / `array_last` | No | No hay helpers globales conflictivos. |
| Vistas Bootstrap 3 de paginación | No | No hay referencias a nombres internos de vistas. |

### Revisión 3: ejecución real en Laravel 13

- Gate mínimo resuelto con PHP 8.3, Laravel 13.12.0, PHPUnit 12.5 y Testbench 11.
- Gate actual resuelto también con Laravel 13.26.1 y las últimas dependencias
  compatibles disponibles durante la auditoría.
- Suite completa ejecutada sobre ambos extremos de Laravel 13: 149 tests y 401
  assertions.
- Prueba de integración añadida para arrancar el service provider, fusionar config
  y resolver sus singletons públicos.
- Pruebas específicas añadidas para el comportamiento seguro de objetos de caché
  con `serializable_classes=false` y con allowlist explícita.
- `composer validate --strict`, `composer audit` y lint PHP forman parte del gate.
- Gate inverso confirmado tanto en el mínimo seguro Laravel 12.61.1 como en
  Laravel 12.67, con PHP 8.2, PHPUnit 11.5 y Testbench 10: la misma suite queda
  verde sin cambios de código.

### Revisión 4: consistencia documental y de entrega

- Requisitos, instalación, testing, caché y reglas de validación actualizados en
  inglés y español.
- Los documentos históricos de arquitectura de caché ahora indican Laravel 12/13.
- La rama de trabajo es `feat/laravel-13-migration`.
- El workflow CI evita que una futura actualización rompa silenciosamente una de
  las dos versiones soportadas.

## Plan de ejecución

1. **Dependencias y metadata**
   - Usar `laravel/framework: ^12.61.1 || ^13.12.0` y `php: ^8.2`.
   - Eliminar requisitos Illuminate redundantes, ya reemplazados por Framework.
   - Migrar PHPUnit y añadir Testbench con constraints por generación.
   - Validar un lock temporal con una versión Laravel 13 parcheada y sin
     advisories; el paquete no distribuye `composer.lock`.

2. **Compatibilidad de runtime**
   - Conservar firmas públicas y comportamientos CRUD/Eloquent existentes.
   - Respetar `cache.serializable_classes=false` sin rebajar la seguridad global.
   - Invalidar lógicamente entradas antiguas mediante `rgc:v2`.
   - Cubrir el provider y los dos caminos de caché con tests.

3. **Compatibilidad continua**
   - Ejecutar CI en Laravel 12/PHP 8.2–8.3 y Laravel 13/PHP 8.3–8.5.
   - Resolver cada fila con el constraint explícito de Laravel, Testbench y PHPUnit.
   - Ejecutar PHPUnit sin cobertura para que el gate no dependa de extensiones.

4. **Documentación y adopción**
   - Publicar la matriz exacta de PHP/Laravel.
   - Documentar el comando de actualización del host y `optimize:clear`.
   - Explicar cuándo los paginadores no se guardan y cómo crear una allowlist
     limitada si la aplicación necesita cachearlos.

5. **Gate de release**
   - `composer validate --strict` sin errores.
   - `composer audit` sin advisories.
   - Suite verde en Laravel 12 y 13.
   - Lint de todos los PHP.
   - Revisión del diff para impedir cambios ajenos o artefactos generados.
   - Publicar una nueva versión minor y probar instalación en una aplicación
     Laravel 13 limpia antes de fusionar a `main`.

## Riesgos y rollback

- Con la configuración segura por defecto de Laravel 13, una consulta paginada
  puede perder hits de caché, pero nunca cambia su tipo de retorno ni intenta
  restaurar clases incompletas. La allowlist permite recuperar ese rendimiento.
- El cambio `rgc:v2` deja claves `rgc:v1` huérfanas hasta su TTL; no las reutiliza.
  Se pueden purgar con el mecanismo operativo del store si se desea recuperar
  espacio inmediatamente.
- Si se revierte la migración, restaurar los constraints anteriores y el prefijo
  `rgc:v1`; no se requiere migración de base de datos.

## Fuentes primarias

- https://laravel.com/docs/13.x/upgrade
- https://laravel.com/docs/13.x/releases
- https://laravel.com/docs/13.x/packages
- https://github.com/laravel/framework/blob/13.x/composer.json
- https://github.com/laravel/framework/security/advisories/GHSA-crmm-hgp2-wgrp
- https://github.com/orchestral/testbench#version-compatibility
- https://getcomposer.org/doc/04-schema.md
- https://getcomposer.org/doc/articles/versions.md
