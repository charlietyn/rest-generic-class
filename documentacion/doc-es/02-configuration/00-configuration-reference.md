# Referencia de configuración

El paquete publica un único archivo de configuración: `config/rest-generic-class.php`.

## Logging

| Clave | Tipo | Default | Descripción |
| --- | --- | --- | --- |
| `logging.rest-generic-class.driver` | string | `single` | Driver de logging usado cuando el paquete crea un canal. |
| `logging.rest-generic-class.path` | string | `storage_path('logs/rest-generic-class.log')` | Ruta del archivo de log. |
| `logging.rest-generic-class.level` | string | `debug` | Nivel de log. |
| `logging.channel.driver` | string | `single` | Definición de canal secundario usado por el service provider. |
| `logging.channel.path` | string | `storage_path('logs/rest-generic-class.log')` | Ruta del canal secundario. |
| `logging.channel.level` | string | `debug` | Nivel del canal secundario. |
| `logging.query` | bool | `false` | Cuando es true, los controladores registran acciones de consulta en `storage/logs/query.log`. |

## Filtrado

| Clave | Tipo | Default | Descripción |
| --- | --- | --- | --- |
| `filtering.max_depth` | int | `5` | Profundidad máxima de anidación para filtros `oper`. |
| `filtering.max_conditions` | int | `100` | Número máximo de condiciones de filtro por solicitud. |
| `filtering.strict_relations` | bool | `true` | Requiere `const RELATIONS` en los modelos (recomendado). |
| `filtering.allowed_operators` | array | (ver configuración) | Operadores permitidos para el filtrado `oper`. |
| `filtering.validate_columns` | bool | `true` | Valida nombres de columnas antes de aplicar filtros. |
| `filtering.strict_column_validation` | bool | `true` | Aplica validación estricta de columnas. |
| `filtering.column_cache_ttl` | int | `3600` | TTL de caché (segundos) para listas de columnas. |

## Validación

| Clave | Tipo | Default | Descripción |
| --- | --- | --- | --- |
| `validation.cache_enabled` | bool | `true` | Activa/desactiva el caché de las queries de validación en el trait `ValidatesExistenceInDatabase`. |
| `validation.cache_ttl` | int | `3600` | TTL del caché en segundos para queries de validación. |
| `validation.cache_prefix` | string | `'validation'` | Prefijo usado para las claves de caché de validación. |
| `validation.connection` | string | `'db'` | Nombre de la conexión de BD usada por las queries de validación. |

## Rutas opcionales de permisos

El paquete puede registrar rutas reutilizables de lectura de permisos. Estan deshabilitadas por defecto para que las aplicaciones existentes mantengan control total de su tabla de rutas.

| Clave | Tipo | Default | Descripcion |
| --- | --- | --- | --- |
| `permissions.roles_relation` | string | `roles` | Nombre de la relacion Eloquent del modelo User que devuelve sus roles. Fallback global; cada modelo puede sobreescribirlo con `const ROLES_RELATION`. Soporta cardinalidad uno-a-muchos (un rol) y muchos-a-muchos (varios roles). |
| `permissions.routes.enabled` | bool | `false` | Cuando es true, carga las rutas de permisos del paquete. |
| `permissions.routes.prefix` | string | `permissions` | Prefijo de rutas. Agrega prefijos de app como `/api` en tu grupo de rutas o kernel HTTP, no aqui. |
| `permissions.routes.middleware` | array | `['api', 'auth:api']` | Middleware aplicado a las rutas del paquete. |
| `permissions.routes.guard` | string | `api` | Guard usado para resolver el usuario autenticado en la ruta opcional del paquete. No filtra el payload salvo que la solicitud tambien envie `guard`. |

Rutas registradas cuando se habilita:

```http
GET /permissions
GET /permissions/by-roles
GET /permissions/by-users
```

Si la aplicacion consumidora tambien define `apiResource('permissions')`, registra las rutas especificas anteriores antes del resource route.

## Contratos de permisos (User y Role)

A partir de 3.0.0 el paquete obliga al integrador a declarar **contratos formales** en sus modelos User y Role (ver [guia detallada](../03-usage/06-permissions.md)). Esta seccion del config es **opcional** y solo activa la validacion temprana en arranque; los contratos siempre se aplican en runtime aunque las claves esten vacias.

| Clave | Tipo | Default | Descripcion |
| --- | --- | --- | --- |
| `permissions.contracts.user_model` | string\|null | `null` | FQCN del modelo User. Si se declara, el `ServiceProvider` valida en `boot()` que la clase implementa `Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles`. |
| `permissions.contracts.role_model` | string\|null | `null` | FQCN del modelo Role. Si se declara, el `ServiceProvider` valida en `boot()` que la clase implementa `Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions`. |

Si una de estas clases no implementa la interface esperada, la app falla en arranque con `RolesContractViolationException` (mensaje accionable que indica exactamente que clase debe implementar que interface).

```php
// Ejemplo
'permissions' => [
    'contracts' => [
        'user_model' => \App\Models\User::class,
        'role_model' => \App\Models\Role::class,
    ],
],
```

**Siguiente:** [Variables de entorno](01-env-vars.md)

[Volver al índice de documentación](../index.md)
