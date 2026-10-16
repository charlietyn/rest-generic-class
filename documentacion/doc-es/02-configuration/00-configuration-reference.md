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

## Autorización del núcleo (v2.9.0)

Claves bajo `authorization` (núcleo, independiente de cualquier paquete de permisos). Un valor `null` recurre a
la clave antigua `permissions.authorization.*` del mismo nombre y después al valor por defecto. Ver los
[contratos de autorización](../04-reference/06-authorization-contracts.md).

| Clave | Por defecto | Significado |
| --- | --- | --- |
| `eager_relations` | `null` → `off` | `require_read` hace que cada relación a-muchos pedida con `?relations=` necesite alguna de las abilities de relación bajo el recurso autorizado, consultadas al Gate de Laravel. Sin una integración de permisos que aporte el ámbito de la petición, falla de forma cerrada (`MissingAuthorizationContextException`). |
| `eager_relations_to_one` | `null` → `allow` | `allow` deja exentas las relaciones a-uno; `require_read` también las comprueba. |
| `relation_abilities` | `['read', 'manage']` | Sufijos de las abilities que conceden una relación (`<recurso>.<relación>.<sufijo>`, cualquiera de ellos). |

`permissions.authorization.eager_relations` y `eager_relations_to_one` (más abajo) siguen funcionando como respaldo.

## Autorización por canales (v2.6.0)

Todas las claves siguientes pertenecen a `permissions.authorization`.

| Clave | Predeterminado | Significado |
| --- | --- | --- |
| `route_prefixes` | `null` | Legacy: el prefijo del grupo Laravel empieza por el nombre del guard. Un array compara prefijos de URI por segmentos (`admin` nunca coincide con `administration`); `[]` no selecciona rutas. Admite prefijos anidados como `api/admin`; gana la primera coincidencia. |
| `naming` | `derived` | Infiere `{version}.{module}.{model}.{action}` (versión/módulo opcionales). `route_name` usa el nombre literal de la ruta, incluidos `store`/`destroy`, sin traducción ni versión adicional; una ruta sin nombre no se resuelve. |
| `on_unresolved` | `allow` | `allow` continúa sin comprobar permisos. `deny` devuelve 403 en cualquier ruta no resuelta que tenga el middleware, incluidas las rutas fuera de los prefijos configurados. |
| `register_gate` | `false` | Registra un `Gate::before` opcional para usuarios con `effectivePermissions()` sin `checkPermissionTo()` de Spatie. También incorpora permisos globales a la unión efectiva del trait. |
| `gate_guard` | `null` | Filtra los permisos efectivos del Gate por guard; `null` admite todos. Usa `api` si ese es tu guard de autenticación. |
| `explicit_globals` | `false` | Fase 2: `false` conserva los globales por módulo (una ruta sin módulo reconocido es global). `true` guarda `restrict=false` solo en las rutas cuya **acción** lleva `'permission_global' => true` (incluso dentro de un módulo); el resto de permisos sincronizados queda restringido (falla cerrando). |
| `catalog_middleware` | `null` | Fase 2, solo catálogo: `null` incluye todas las rutas del ámbito. Un array incluye solo las rutas cuyo `gatherMiddleware()` contiene un string que empieza por uno de los marcadores (alias o FQCN); `[]` no incluye ninguna. Ignora middleware que no sean string. |
| `eager_relations` | `off` | v2.8.0: `require_read` hace que cada relación a muchos pedida con `?relations=` exija `<recurso>.<relación>.read` o `….manage` (recurso = acción autorizada sin su último segmento). Una relación denegada es un 403 con `filtering.strict_relations=true` y se omite en caso contrario. Se comprueba antes de la caché. |
| `eager_relations_to_one` | `allow` | v2.8.0: `allow` exime a las relaciones a uno (`belongsTo`, `hasOne`, `morphOne`, `hasOneThrough`, `morphTo`) de `eager_relations`; `require_read` también las comprueba. |

`route_prefixes` selecciona rutas; no define guards de autenticación. El parámetro del middleware y `--guard` del comando deben seguir usando guards reales. Ya no se lee la antigua clave `route-permissions`. `passesFilters()` permanece como punto de extensión sobrescribible, sin reglas de filtrado incorporadas.

El valor `allow` se conserva por compatibilidad, pero queda obsoleto y es inseguro en rutas protegidas; se prevé cambiarlo a `deny` en v3.0.0.

La corrección legacy de fase 2 recupera la coincidencia exacta de módulos de v2.5.0 cuando `route_prefixes=null`: la URI debe tener más de dos segmentos no vacíos, empezar por el segmento literal del guard y usar el nombre exacto del módulo. `api/mod_security/users` con módulo `ModSecurity` produce `users.index`; `api/ModSecurity/users` produce `ModSecurity.users.index`. La coincidencia snake_case solo se aplica con prefijos de canales explícitos. Se conservan las correcciones de versión y rutas sin controlador introducidas en v2.6.0.

`explicit_globals` controla lo que escribe la sincronización, no el lector ni el Gate. Declara `permission_global` como atributo de un grupo de rutas o con `setAction()`; nunca con `->defaults()`, que Laravel convierte en un parámetro de ruta que llega a los controladores (y el resolver lo ignora). Resincronizar actualiza `restrict` en las filas existentes seleccionadas. El Gate opcional sigue concediendo los permisos persistidos `notRestricted()` a cualquier usuario autenticado de su guard. `catalog_middleware` no cambia la autorización de peticiones, no expande alias/grupos y no elimina filas: borra explícitamente los permisos obsoletos tras revisar sus asignaciones. Las filas excluidas no se actualizan, incluido su estado global anterior.

```php
// config/rest-generic-class.php -> permissions.authorization
'authorization' => [
    'route_prefixes' => ['admin', 'client', 'mobile'],
    'naming' => 'route_name',
    'on_unresolved' => 'deny',
    'register_gate' => true,
    'gate_guard' => 'api',
    'explicit_globals' => true,
    'catalog_middleware' => ['maybe.permission', \Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize::class],
],
```

```php
// routes/admin.php: atributo de grupo, copiado a la acción de cada ruta
Route::group(['as' => 'admin.', 'permission_global' => true], function () {
    Route::get('auth/permissions', [AuthController::class, 'permissions'])->name('auth.permissions');
    Route::get('auth/user-profile', [AuthController::class, 'profile'])->name('auth.user-profile');
});

// una ruta suelta fuera de un grupo
$route = Route::get('auth/modules', [AuthController::class, 'modules'])->name('admin.auth.modules');
$route->setAction([...$route->getAction(), 'permission_global' => true]);
```
