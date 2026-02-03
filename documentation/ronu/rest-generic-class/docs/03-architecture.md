# Architecture

## Componentes principales
```
HTTP Request
  -> Middleware (TransformData / InjectRequestParams / SpatieAuthorize)
  -> RestController
  -> BaseService
  -> Eloquent Builder / DB
  -> Response JSON
```

## Diagrama textual del flujo
- **Middleware `TransformData`** valida y normaliza payloads antes de entrar a controller.
- **`RestController`** extrae parámetros (`relations`, `select`, `oper`, etc.) y delega a `BaseService`.
- **`BaseService`** aplica filtros (`oper`, `attr/eq`), carga de relaciones y paginación.
- **`HasDynamicFilter`** procesa la gramática de filtros y aplica `where`/`whereHas`.
- **`DatabaseErrorParser`** transforma errores de DB en mensajes legibles.

## Componentes clave
| Componente | Ubicación | Rol |
| --- | --- | --- |
| `RestController` | `src/Core/Controllers/RestController.php` | Endpoints REST base. |
| `BaseService` | `src/Core/Services/BaseService.php` | CRUD, filtros y exportaciones. |
| `BaseModel` | `src/Core/Models/BaseModel.php` | Modelo base con soporte de jerarquía y escenarios. |
| `BaseFormRequest` | `src/Core/Requests/BaseFormRequest.php` | Validación por escenarios. |
| `HasDynamicFilter` | `src/Core/Traits/HasDynamicFilter.php` | Motor de filtros. |
| `DatabaseErrorParser` | `src/Core/Helpers/DatabaseErrorParser.php` | Parseo de errores DB. |
| `SpatieAuthorize` | `src/Core/Middleware/SpatieAuthorize.php` | Autorización basada en permisos. |

## Evidence
- File: src/Core/Middleware/TransformData.php
  - Symbol: TransformData::handle()
  - Notes: validación previa a controller.
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController::{process_request,index,store,update}
  - Notes: parseo de parámetros y delegación a `BaseService`.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::{list_all,applyOperTree,relations,pagination}
  - Notes: flujo principal de consulta y CRUD.
- File: src/Core/Traits/HasDynamicFilter.php
  - Symbol: HasDynamicFilter::applyFilters()
  - Notes: aplicación de filtros con operadores.
- File: src/Core/Helpers/DatabaseErrorParser.php
  - Symbol: DatabaseErrorParser::parse()
  - Notes: mapeo de errores de DB.
- File: src/Core/Middleware/SpatieAuthorize.php
  - Symbol: SpatieAuthorize::handle()
  - Notes: autorización por permisos desde rutas/controladores.
