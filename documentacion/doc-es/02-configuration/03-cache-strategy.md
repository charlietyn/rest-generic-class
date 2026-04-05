# Estrategia de caché (genérica y configurable)

Este paquete soporta una estrategia de caché **agnóstica al backend** usando Laravel Cache, por lo que puedes usar:

- `redis`
- `database`
- `file`
- `memcached`
- cualquier otro store soportado por Laravel

## Claves de configuración

El comportamiento de caché se configura en `config/rest-generic-class.php` dentro de `cache`:

| Clave | Descripción |
| --- | --- |
| `cache.enabled` | Habilita/deshabilita la caché del paquete. |
| `cache.store` | Nombre del store de Laravel (ejemplo: `redis`, `database`). |
| `cache.ttl` | TTL por defecto (segundos). |
| `cache.ttl_by_method.list_all` | Override de TTL para lecturas de listados. |
| `cache.ttl_by_method.get_one` | Override de TTL para lecturas de un elemento. |
| `cache.cacheable_methods` | Métodos de lectura autorizados para usar caché. |
| `cache.vary.headers` | Headers incluidos en la identidad de caché (seguridad tenant/locale). |

## `.env` recomendado

```env
REST_CACHE_ENABLED=true
REST_CACHE_STORE=redis
REST_CACHE_TTL=60
REST_CACHE_TTL_LIST=60
REST_CACHE_TTL_ONE=30
```

Para cambiar de backend sin tocar código:

```env
REST_CACHE_STORE=database
```

## Cómo funcionan las keys request-aware

En operaciones de lectura, la identidad de caché debe variar por forma de request:

- query params (`select`, `relations`, `oper`, `pagination`, `orderby`, etc.)
- ruta/path y método HTTP
- scope de autenticación (si la respuesta depende del usuario)
- headers tenant/locale definidos en `cache.vary.headers`
- versión de caché por modelo (para invalidar tras escrituras)

Esto evita contaminación de caché entre distintos esquemas de consulta y contextos.

## Control de caché por servicio

Los servicios hijos pueden sobreescribir el comportamiento de caché sin cambiar la config global:

```php
class ProductService extends BaseService
{
    protected ?bool $cacheable = true;   // forzar caché ON
    protected ?int $cacheTtl = 300;      // 5 minutos
    protected array $cacheableOperations = ['list_all']; // solo cachear listados

    public function __construct() {
        parent::__construct(Product::class);
    }
}

class OrderService extends BaseService
{
    protected ?bool $cacheable = false;  // nunca cachear

    public function __construct() {
        parent::__construct(Order::class);
    }
}
```

| Propiedad | Default | Comportamiento |
| --- | --- | --- |
| `$cacheable` | `null` | `null` = usa config global, `true` = forzar on, `false` = forzar off |
| `$cacheTtl` | `null` | `null` = usa config global, entero = segundos |
| `$cacheableOperations` | `[]` | vacío = usa config global, no vacío = solo esos métodos |

**Cadena de prioridad para TTL:** parámetro `cache_ttl` del request > `$this->cacheTtl` > config `ttl_by_method` > config `ttl`

## Modelo de invalidación

Usar **keys versionadas por modelo**:

1. mantener una key de versión por modelo
2. incluir la versión en cada key de lectura
3. incrementar versión tras escrituras exitosas (`create`, `update`, `destroy`)

Esto funciona con todos los stores, incluso los que no soportan tags.

## Invalidación cruzada de entidades

Cuando un modelo se carga como relación (ej: User carga Role), actualizar el modelo relacionado debe invalidar también la caché del padre. Dos mecanismos trabajan juntos:

### Automático: versiones compuestas

Cuando se piden `relations`, `buildCacheKey()` incluye la versión de caché de cada modelo relacionado en el fingerprint. Si la versión de Role cambia, la clave de caché de User cambia automáticamente.

```
GET /users/1?relations=["roles"]
→ clave incluye: version=5, rel_versions={roles: 2}

PUT /roles/1 {name: "Super Admin"}
→ versión de Role sube a 3

GET /users/1?relations=["roles"]
→ clave incluye: version=5, rel_versions={roles: 3} ← clave diferente → cache miss
```

Esto funciona para relaciones anidadas: `relations=["roles.permissions"]` rastrea versiones de Role y Permission.

### Manual: CACHE_INVALIDATES

Para edge cases donde datos relacionados aparecen sin `relations` explícito (ej: via accessors `$appends`), se declaran dependencias en el modelo:

```php
class Role extends BaseModel
{
    const CACHE_INVALIDATES = [
        \App\Models\User::class,  // invalidar versión de User cuando Role cambia
    ];
}
```

Cuando Role se modifica, `bumpCacheVersion()` también incrementa la versión de User.

**Siguiente:** [Publicar assets](02-publishing-assets.md)

[Volver al índice de documentación](../index.md)
