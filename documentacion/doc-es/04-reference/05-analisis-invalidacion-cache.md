# Analisis de Invalidacion de Cache (En Profundidad)

Este documento proporciona un analisis a nivel senior de la estrategia de
invalidacion de cache en `rest-generic-class`, cubriendo el control de cache
por servicio y la invalidacion cruzada de entidades.

---

## Problema

### 1. El toggle de cache es solo global

`shouldUseCache()` solo revisa la variable de entorno global `REST_CACHE_ENABLED`.
No hay forma de hacer que `ProductService` sea cacheable mientras `OrderService` no.

### 2. Datos obsoletos entre entidades

Cuando un Usuario se cachea con su Rol (via eager loading), y luego el Rol se
actualiza, la cache del Usuario sigue devolviendo datos obsoletos del Rol porque
solo se incrementa la version de cache del Rol — la version del Usuario no cambia.

---

## Solucion: Estrategia Dual (Versiones Compuestas + CACHE_INVALIDATES)

### Control de cache por servicio

Tres nuevas propiedades en `BaseService`:

```php
protected ?bool $cacheable = null;        // null = config global, true/false = override
protected ?int $cacheTtl = null;          // null = config global, int = segundos
protected array $cacheableOperations = []; // vacio = config global
```

Cadena de prioridad:
- **Habilitado**: `$cacheable` (servicio) > `cache.enabled` (config) > parametro `cache` (request)
- **TTL**: `cache_ttl` (request) > `$cacheTtl` (servicio) > `ttl_by_method` (config) > `ttl` (config)

### Automatico: versiones compuestas en la clave

`buildCacheKey()` ahora incluye `rel_versions` — la version de cache de cada
modelo de relacion cargado via eager loading. Cuando se escribe un modelo
relacionado, su version cambia, lo que cambia la clave de cache de cualquier
padre que lo carga.

```php
$fingerprint = [
    // ... campos existentes ...
    'version' => $this->getCacheVersion(),
    'rel_versions' => $this->getRelationVersions($params), // NUEVO
];
```

`getRelationVersions()` resuelve relaciones anidadas con dot-notation recorriendo
la cadena de relaciones Eloquent:

```
relations=["roles.permissions"]
-> rel_versions = { "roles": 2, "roles.permissions": 4 }
```

### Manual: constante CACHE_INVALIDATES

Para edge cases donde datos relacionados aparecen sin `relations` explicito
(ej: accessors `$appends`), los modelos declaran que otros modelos deben
invalidarse cuando ellos cambian:

```php
class Role extends BaseModel
{
    const CACHE_INVALIDATES = [\App\Models\User::class];
}
```

`bumpCacheVersion()` ahora propaga a todos los modelos listados en `CACHE_INVALIDATES`.

---

## Por Que Esta Estrategia

### Alternativas comparadas

| Criterio | Solo CACHE_INVALIDATES | Solo versiones compuestas | Combinada (elegida) | Observers |
|----------|----------------------|--------------------------|---------------------|-----------|
| Config manual | Alta | Ninguna | Minima | Media |
| Cubre relaciones anidadas | Si declaras | Parcial (1 nivel) | Si | Si declaras |
| Cubre cambios fuera de BaseService | No | No | Parcial | Si |
| Rendimiento | O(1) bump | O(n) reads al construir key | O(n) reads + O(1) | O(n) eventos |
| Riesgo datos obsoletos | Alto (error humano) | Bajo | Muy bajo | Bajo |
| Compatible hacia atras | 100% | 100% | 100% | 95% |

### Por que NO solo CACHE_INVALIDATES manual

En un sistema con 15+ modelos, exigir que los desarrolladores mantengan
`CACHE_INVALIDATES` en cada modelo es propenso a errores. Un olvido significa
datos obsoletos silenciosos en produccion — sin error, sin warning, solo datos
incorrectos. Debuggear esto puede tomar horas.

### Por que NO solo versiones compuestas

Las versiones compuestas manejan automaticamente las `relations` explicitas. Pero
no pueden detectar datos incluidos via accessors `$appends` o relaciones lazy-loaded
en `jsonSerialize()`. Ejemplo:

```php
class User extends BaseModel {
    protected $appends = ['role_name'];
    public function getRoleNameAttribute() {
        return $this->role?->name; // lazy load, sin parametro relations
    }
}
```

Aqui `getRelationVersions()` retorna `[]` (no hay `relations` en params), pero
el JSON cacheado incluye datos del rol. `CACHE_INVALIDATES` cubre este edge case.

### Por que NO Observers

1. El ServiceProvider tendria que registrar listeners en TODOS los subclases de BaseModel
2. `update_multiple` con 500 registros dispara 500 eventos de observer
3. `DB::table()->update()` no dispara observers — falsa sensacion de seguridad
4. Mas dificil de debuggear ("por que se invalido mi cache?")

---

## Escenarios Detallados

### Escenario A: Usuario con Roles (el problema original)

```
Paso 1: GET /api/users/1?relations=["roles"]
  -> clave: { version: 5, rel_versions: { roles: 2 } }
  -> cacheado: { id: 1, name: "Juan", roles: [{ name: "Admin" }] }

Paso 2: PUT /api/roles/1 { name: "Super Admin" }
  -> version Role: 2 -> 3

Paso 3: GET /api/users/1?relations=["roles"]
  -> clave: { version: 5, rel_versions: { roles: 3 } } <- DIFERENTE
  -> cache miss -> query fresca -> { roles: [{ name: "Super Admin" }] }
```

Resultado: datos correctos, cero configuracion necesaria.

### Escenario B: Cadena de 3 niveles — User -> Role -> Permission

```php
class Permission extends BaseModel {
    const CACHE_INVALIDATES = [Role::class, User::class];
}
```

```
Paso 1: GET /api/users/1?relations=["roles.permissions"]
  -> rel_versions: { roles: 2, "roles.permissions": 4 }

Paso 2: PUT /api/permissions/3 { name: "can_delete_users" }
  -> incrementa: Permission 4->5, Role 2->3, User 5->6

Paso 3: GET /api/users/1?relations=["roles.permissions"]
  -> rel_versions: { roles: 3, "roles.permissions": 5 } -> cache miss -> fresco
```

### Escenario C: Invalidacion selectiva por relaciones cargadas

```
Request A: GET /api/users?relations=["roles"]        -> rel_versions: { roles: 2 }
Request B: GET /api/users?relations=["department"]    -> rel_versions: { department: 7 }
Request C: GET /api/users?relations=["roles","dept"]  -> rel_versions: { roles: 2, department: 7 }
```

Si solo cambia Department, solo los requests B y C se invalidan. El request A
sigue valido — eficiente y selectivo.

### Escenario D: Accessor sin relaciones explicitas

```php
class User extends BaseModel {
    protected $appends = ['full_role_name'];
    public function getFullRoleNameAttribute() {
        return $this->roles->pluck('name')->join(', ');
    }
}

class Role extends BaseModel {
    const CACHE_INVALIDATES = [User::class];
}
```

```
GET /api/users/1  (sin parametro relations)
  -> rel_versions: {} -> cacheado solo con version de User

PUT /api/roles/1 { name: "Super Admin" }
  -> incrementa version de Role Y version de User (via CACHE_INVALIDATES)

GET /api/users/1
  -> version de User cambio -> clave diferente -> datos frescos
```

---

## Diagrama de Decision

```
Tu servicio necesita cache?
  |-- NO -> protected ?bool $cacheable = false;
  |-- SI -> TTL personalizado?
        |-- SI -> protected ?int $cacheTtl = 300;
        |-- NO -> Usa config global (default)

Tu modelo se carga como relacion por otros modelos?
  |-- NO -> No necesitas CACHE_INVALIDATES
  |-- SI -> Los consumidores siempre usan parametro relations explicito?
        |-- SI -> Las versiones compuestas lo manejan automaticamente
        |-- NO (accessors, $appends) -> Declara CACHE_INVALIDATES
```

---

## Archivos Modificados

| Archivo | Cambios |
|---------|---------|
| `src/Core/Services/BaseService.php` | Propiedades `$cacheable`, `$cacheTtl`, `$cacheableOperations`; metodos `shouldUseCache()`, `resolveCacheTtl()`, `buildCacheKey()`, `bumpCacheVersion()` modificados; metodo `getRelationVersions()` agregado |
| `src/Core/Models/BaseModel.php` | Constante `CACHE_INVALIDATES` agregada |
| `config/rest-generic-class.php` | Sin cambios (compatible hacia atras) |

[Volver al indice de documentacion](../index.md)
