# API pública

Este paquete expone clases, traits y helpers pensados para su uso en tu aplicación Laravel.

## Controladores

- `Ronu\RestGenericClass\Core\Controllers\RestController`
  - `index(Request $request)`
  - `getOne(Request $request)`
  - `show(Request $request, $id)`
  - `store(BaseFormRequest $request)`
  - `update(Request $request, $id)`
  - `updateMultiple(Request $request)`
  - `destroy($id)`
  - `deleteById(Request $request)`
  - `export_excel(Request $request)`
  - `export_pdf(Request $request)`

## Servicios

- `Ronu\RestGenericClass\Core\Services\BaseService`
  - `list_all(array $params)`
  - `get_one(array $params)`
  - `create(array $params)`
  - `update(array $attributes, $id)`
  - `update_multiple(array $params)`
  - `destroy($id)`
  - `destroybyid($id)`
  - `exportExcel(array $params)`
  - `exportPdf(array $params)`
  - Helpers de jerarquía: `listHierarchy()`, `showHierarchy()`

## Modelos

- `Ronu\RestGenericClass\Core\Models\BaseModel`
  - Define constantes `MODEL`, `RELATIONS`, `PARENT` y `HIERARCHY_FIELD_ID`.
  - Helpers de validación: `self_validate()`, `validate_all()`.
  - Helpers de jerarquía: `hierarchyParent()`, `hierarchyChildren()`.
- `Ronu\RestGenericClass\Core\Models\BaseModelMongo`
  - Equivalente para MongoDB con helpers de validación.
- `Ronu\RestGenericClass\Core\Models\SpatieRole`
- `Ronu\RestGenericClass\Core\Models\SpatiePermission`

## Requests

- `Ronu\RestGenericClass\Core\Requests\BaseFormRequest`
  - Selección de escenario con `_scenario` y detección de modo masivo.
  - Helper `validate_request()` para uso en middleware.

## Traits

- `HasDynamicFilter` (filtrado de consultas)
- `HandlesQueryExceptions`
- `HasPermissionsController` / `HasPermissionsService`
- `HasReadableUserPermissions` / `HasReadableRolePermissions`

## Helpers

- `RequestBody` para leer parámetros del body en distintos métodos HTTP.
- `DatabaseErrorParser` para traducir errores de base de datos.
- `HelpersValidations` para validación única basada en arrays.

[Volver al índice de documentación](../index.md)

## Evidencia
- Archivo: src/Core/Controllers/RestController.php
  - Símbolo: clase RestController y métodos CRUD
  - Notas: Define la superficie del controlador para operaciones REST.
- Archivo: src/Core/Services/BaseService.php
  - Símbolo: clase BaseService y métodos CRUD/exportación/jerarquía
  - Notas: Define la API a nivel de servicio.
- Archivo: src/Core/Models/BaseModel.php
  - Símbolo: constantes y helpers de validación
  - Notas: Define contratos del modelo y helpers de validación/jerarquía.
- Archivo: src/Core/Models/BaseModelMongo.php
  - Símbolo: BaseModelMongo
  - Notas: Clase base de modelo MongoDB.
- Archivo: src/Core/Requests/BaseFormRequest.php
  - Símbolo: BaseFormRequest::getScenario(), BaseFormRequest::validate_request()
  - Notas: Superficie de validación de requests.
- Archivo: src/Core/Traits/HasDynamicFilter.php
  - Símbolo: HasDynamicFilter::scopeWithFilters()
  - Notas: Trait de filtrado usado por BaseService.
- Archivo: src/Core/Helpers/RequestBody.php
  - Símbolo: RequestBody::get(), RequestBody::all()
  - Notas: Interface de helper para body de requests.
