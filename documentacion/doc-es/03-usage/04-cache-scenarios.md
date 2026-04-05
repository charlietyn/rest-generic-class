# Escenarios de caché (ejemplos JSON)

Esta página explica si la estrategia de caché es confiable para variantes reales de request.

## Escenario 1: columnas seleccionadas + relaciones

```json
{
  "select": ["id", "name", "price"],
  "relations": ["category:id,name"]
}
```

Esperado:
- cambios en `select` o `relations` producen keys distintas
- reutilización segura cuando el esquema de request es idéntico

## Escenario 2: filtros avanzados `oper`

```json
{
  "oper": {
    "and": [
      "status|=|active",
      "price|>=|100",
      "stock|>|0"
    ]
  }
}
```

Esperado:
- el árbol de filtros se refleja en la identidad de caché
- árboles equivalentes semánticamente pero distintos textualmente pueden generar keys diferentes (normal si no hay canonicalización profunda)

## Escenario 3: variantes de paginación

Offset:

```json
{
  "pagination": {"page": 2, "pageSize": 25},
  "orderby": [{"id": "desc"}]
}
```

Cursor:

```json
{
  "pagination": {"infinity": true, "pageSize": 25, "cursor": "abc"}
}
```

Esperado:
- cambios de página/cursor generan entradas aisladas de caché

## Escenario 4: modo jerárquico

```json
{
  "hierarchy": {
    "enabled": true,
    "filter_mode": "with_descendants",
    "children_key": "children",
    "max_depth": 3
  }
}
```

Esperado:
- requests con jerarquía y sin jerarquía no colisionan en caché

## Escenario 5: control por request

```json
{
  "cache": false,
  "cache_ttl": 120
}
```

Esperado:
- `cache=false` evita uso de caché
- `cache_ttl` sobrescribe TTL por defecto cuando la caché está habilitada

## Escenario 6: invalidación cruzada de entidades (relaciones)

Setup: modelo User tiene relación `roles`, se modifica el modelo Role.

```
Paso 1: GET /api/users/1?relations=["roles"]
  → cacheado con fingerprint: { version: 5, rel_versions: { roles: 2 } }
  → resultado: { id: 1, name: "Juan", roles: [{ id: 1, name: "Admin" }] }

Paso 2: PUT /api/roles/1 { name: "Super Admin" }
  → versión de Role sube de 2 → 3

Paso 3: GET /api/users/1?relations=["roles"]
  → fingerprint: { version: 5, rel_versions: { roles: 3 } } ← CAMBIÓ
  → cache miss → datos frescos con nombre de rol actualizado
```

Esperado:
- actualizar un modelo relacionado invalida automáticamente respuestas cacheadas que lo incluyen
- no requiere configuración manual cuando las relaciones se cargan explícitamente

## Escenario 7: relaciones anidadas (3 niveles)

```
GET /api/users/1?relations=["roles.permissions"]
  → rel_versions: { roles: 2, "roles.permissions": 4 }

PUT /api/permissions/3 { name: "can_delete" }
  → versión de Permission sube
  → si CACHE_INVALIDATES = [Role::class, User::class], esas versiones también suben

GET /api/users/1?relations=["roles.permissions"]
  → rel_versions cambió → cache miss → datos frescos
```

Esperado:
- relaciones anidadas con dot-notation se rastrean en cada nivel
- `CACHE_INVALIDATES` propaga la invalidación a modelos padre

## Escenario 8: control de caché por servicio

```php
class ProductService extends BaseService {
    protected ?bool $cacheable = true;
    protected ?int $cacheTtl = 600;
}

class AuditLogService extends BaseService {
    protected ?bool $cacheable = false; // nunca cachear
}
```

Esperado:
- ProductService cachea con TTL de 10 minutos sin importar la config global
- AuditLogService nunca cachea aunque la caché global esté habilitada
- servicios sin override usan la config global (compatible hacia atrás)

## Escenario 9: accessor sin relaciones explícitas (CACHE_INVALIDATES)

```php
class User extends BaseModel {
    protected $appends = ['role_name'];
    public function getRoleNameAttribute() {
        return $this->role?->name;
    }
}

class Role extends BaseModel {
    const CACHE_INVALIDATES = [User::class];
}
```

```
GET /api/users/1  (sin parámetro relations)
  → rel_versions: {} (vacío)
  → la respuesta incluye role_name via $appends

PUT /api/roles/1 { name: "Super Admin" }
  → bumpCacheVersion() incrementa versiones de Role Y User (via CACHE_INVALIDATES)

GET /api/users/1
  → versión de User cambió → clave diferente → datos frescos
```

Esperado:
- `CACHE_INVALIDATES` cubre casos donde datos relacionados se serializan sin `relations` explícito

## ¿Soporta todas las variantes?

Respuesta práctica:
- **Sí, para la mayoría de patrones de request en producción** de este paquete.
- **No está 100% canonicalizado** para toda equivalencia semántica cuando cambia el orden del JSON.
- **La invalidación cruzada de entidades** se maneja automáticamente para relaciones explícitas y via `CACHE_INVALIDATES` para las implícitas.

Si los clientes envían payloads equivalentes con mucho cambio de orden, agrega canonicalización antes de hashear el payload de la key.

[Volver al índice de documentación](../index.md)
