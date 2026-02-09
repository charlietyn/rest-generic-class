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

## Modelo de invalidación

Usar **keys versionadas por modelo**:

1. mantener una key de versión por modelo
2. incluir la versión en cada key de lectura
3. incrementar versión tras escrituras exitosas (`create`, `update`, `destroy`)

Esto funciona con todos los stores, incluso los que no soportan tags.

**Siguiente:** [Publicar assets](02-publishing-assets.md)

[Volver al índice de documentación](../index.md)
