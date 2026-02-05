# Conceptos

## Bloques principales

- **BaseModel**: Extiende Eloquent con validación por escenarios y allowlists de relaciones. Úsalo en los modelos de tu aplicación.
- **BaseService**: Orquesta CRUD, filtrado dinámico, carga de relaciones, paginación y soporte de jerarquías.
- **RestController**: Controlador HTTP con endpoints CRUD comunes y helpers para parsear parámetros REST.
- **BaseFormRequest**: Clase base de validación que soporta selección de escenarios y modos masivos.

## Parámetros de consulta

El controlador parsea parámetros de query y body en un único mapa. El servicio usa ese mapa para construir la consulta:

- `select`: Seleccionar columnas del modelo principal.
- `relations`: Cargar relaciones (con selección opcional de campos como `relation:id,name`).
- `oper`: Filtrado estructurado que soporta relaciones anidadas y muchos operadores.
- `orderby`: Instrucciones de ordenamiento.
- `pagination`: Paginación por offset o por cursor (con `infinity`).
- `_nested`: Cuando es true, los filtros de `oper` se aplican a relaciones y a la consulta raíz.
- `attr`/`eq`: Filtros de igualdad heredados.
- `hierarchy`: Habilita listado/consulta jerárquica cuando el modelo define `HIERARCHY_FIELD_ID`.

## Postura de seguridad

El sistema de filtrado aplica:

- **Relaciones en allowlist** mediante `const RELATIONS` (estricto por defecto).
- **Límites de profundidad y de condiciones** para evitar consultas demasiado complejas.
- **Allowlist de operadores** vía configuración.

## Utilidades de permisos

El paquete incluye middleware y traits que integran con **spatie/laravel-permission**. Estas utilidades derivan permisos a partir de metadata de rutas y ayudan a gestionar permisos de roles/usuarios cuando se usan en tu aplicación.

**Siguiente:** [Requisitos](../01-getting-started/00-requirements.md)

[Volver al índice de documentación](../index.md)
