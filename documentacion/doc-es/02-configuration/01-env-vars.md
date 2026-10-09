# Variables de entorno

El paquete lee variables de entorno unicamente desde el archivo de configuracion. Esto es seguro para config caching.

| Variable | Default | Usado para |
| --- | --- | --- |
| `LOG_LEVEL` | `debug` | Define el nivel de log del canal de logging del paquete. |
| `LOG_QUERY` | `false` | Habilita el logging de consultas en controladores cuando es `true`. |
| `REST_VALIDATE_COLUMNS` | `true` | Habilita validacion de columnas para filtrado. |
| `REST_STRICT_COLUMNS` | `true` | Habilita comportamiento de validacion estricta de columnas. |
| `REST_VALIDATION_CACHE_ENABLED` | `true` | Activa/desactiva el cache de queries de validacion de BD (trait `ValidatesExistenceInDatabase`). |
| `REST_VALIDATION_CACHE_TTL` | `3600` | TTL del cache en segundos para queries de validacion. |
| `REST_VALIDATION_CACHE_PREFIX` | `'validation'` | Prefijo para las claves de cache de validacion. |
| `REST_VALIDATION_CONNECTION` | `'db'` | Nombre de la conexion de BD usada por las queries de validacion. |
| `REST_PERMISSIONS_ROLES_RELATION` | `roles` | Nombre por defecto de la relacion del modelo User que devuelve sus roles. Cada modelo puede sobreescribirlo con `const ROLES_RELATION`. |
| `REST_PERMISSIONS_ROUTES_ENABLED` | `false` | Habilita las rutas de lectura de permisos provistas por el paquete. |
| `REST_PERMISSIONS_ROUTES_PREFIX` | `permissions` | Prefijo para las rutas opcionales de permisos. |
| `REST_PERMISSIONS_ROUTES_MIDDLEWARE` | `api,auth:api` | Lista de middleware separada por comas para las rutas opcionales. |
| `REST_PERMISSIONS_ROUTES_GUARD` | `api` | Guard usado para resolver el usuario autenticado en rutas opcionales de permisos. |
| `REST_PERMISSIONS_USER_MODEL` | _(no definida)_ | FQCN del modelo User para validacion temprana del contrato `ProvidesRoles` en boot. Opcional; si esta vacia la validacion ocurre en runtime. |
| `REST_PERMISSIONS_ROLE_MODEL` | _(no definida)_ | FQCN del modelo Role para validacion temprana del contrato `ProvidesRolePermissions` en boot. Opcional; si esta vacia la validacion ocurre en runtime. |

**Siguiente:** [Estrategia de cache](03-cache-strategy.md)

[Volver al indice de documentacion](../index.md)

## Autorización por canales (v2.6.0)

| Variable | Predeterminado | Función |
| --- | --- | --- |
| `REST_AUTHZ_ROUTE_PREFIXES` | sin definir (`null`) | Prefijos separados por comas; elimina espacios. Un valor vacío produce `[]`. |
| `REST_AUTHZ_NAMING` | `derived` | `derived` o `route_name`. |
| `REST_AUTHZ_ON_UNRESOLVED` | `allow` | `allow` o `deny`; usa `deny` en rutas protegidas. |
| `REST_AUTHZ_REGISTER_GATE` | `false` | Activa el Gate de permisos efectivos y la unión con globales. |
| `REST_AUTHZ_GATE_GUARD` | sin definir (`null`) | Guard opcional, por ejemplo `api`. |
| `REST_AUTHZ_GLOBAL_PERMISSIONS` | sin definir (`null`) | Fase 2: patrones `Str::is` de nombres globales separados por comas. Sin definir conserva los globales de rutas sin módulo; un valor vacío produce `[]` (ningún global). |
| `REST_AUTHZ_CATALOG_MIDDLEWARE` | sin definir (`null`) | Fase 2: marcadores de middleware, alias o FQCN, separados por comas. Sin definir incluye todas las rutas del ámbito; vacío produce `[]` (ninguna ruta en el catálogo). |

Ambas listas recortan espacios, descartan entradas vacías y conservan valores como `0`. `null` y una lista vacía tienen significados distintos. Ejemplo:

```dotenv
REST_AUTHZ_GLOBAL_PERMISSIONS=*.auth.permissions,*.auth.user-profile,*.auth.modules
REST_AUTHZ_CATALOG_MIDDLEWARE=maybe.permission
```

Actualiza la caché de configuración y después sincroniza permisos para aplicar estas opciones. Cambiar el entorno por sí solo no actualiza restricciones persistidas ni elimina permisos obsoletos. Consulta la [referencia de configuración](00-configuration-reference.md).
