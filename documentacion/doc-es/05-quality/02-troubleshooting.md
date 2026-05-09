# Solución de problemas

## "Relation 'x' is not allowed"

**Causa**: La relación no está en `const RELATIONS` y `filtering.strict_relations` está habilitado.

**Solución**: Agrega la relación a `RELATIONS` en el modelo o desactiva el modo estricto en config (no recomendado).

---

## Errores de "Maximum nesting depth" / "Maximum conditions"

**Causa**: `oper` excede `filtering.max_depth` o `filtering.max_conditions`.

**Solución**: Reduce la complejidad del filtro o aumenta límites en `config/rest-generic-class.php`.

---

## "Invalid hierarchy mode" o jerarquía no soportada

**Causa**: `hierarchy.filter_mode` inválido o falta `HIERARCHY_FIELD_ID` en el modelo.

**Solución**: Usa un modo válido (`match_only`, `with_ancestors`, `with_descendants`, `full_branch`, `root_filter`) y define el campo jerárquico en el modelo.

---

## Fallan los métodos de exportación

**Causa**: `exportExcel()` o `exportPdf()` se llaman sin instalar paquetes opcionales.

**Solución**: Instala `maatwebsite/excel` y/o `barryvdh/laravel-dompdf`.

---

## La autorización Spatie falla inesperadamente

**Causa**: Cache de permisos sin refrescar o desajuste de tenant/guard.

**Solución**: Limpia el cache de permisos de Spatie y asegura que el guard/team ID esté definido antes de la autorización.

---

## "The authenticated user model must implement ... ProvidesRoles" (HTTP 500)

**Causa**: El modelo `User` no declara `implements ProvidesRoles`. La librería 3.0.0+ obliga a este contrato para resolver permisos vía roles.

**Solución**: Añadir el `implements` y el método `provideRoles()` al modelo. Ejemplo mínimo:

```php
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles;

class User extends Authenticatable implements ProvidesRoles
{
    public function provideRoles(): \Illuminate\Support\Collection
    {
        return $this->load('roles.enabled_permissions')->roles;
    }
}
```

Ver [guía de permisos](../03-usage/06-permissions.md#5-migración-desde-2x-a-300).

---

## `RolesContractViolationException::roleMissingContract` en runtime

**Causa**: `provideRoles()` devolvió uno o más objetos que no implementan `ProvidesRolePermissions` (por ejemplo, `stdClass`, modelos legacy, o un `Role` que olvidó el `implements`).

**Solución**: Asegurar que cada elemento devuelto por `provideRoles()` sea una instancia de un modelo `Role` que implemente la interface. Si extiendes `SpatieRole` y usas el trait `HasReadableRolePermissions`, basta con añadir `implements ProvidesRolePermissions`.

---

## La app falla al arrancar con `RolesContractViolationException`

**Causa**: Has declarado `permissions.contracts.user_model` o `permissions.contracts.role_model` en `config/rest-generic-class.php` apuntando a una clase que no implementa la interface esperada. Esa validación se ejecuta en `RestGenericClassServiceProvider::boot()`.

**Solución**: Implementar el contrato en la clase declarada o eliminar la entrada del config si todavía no aplica.

---

## N+1 al consultar `/api/permissions`

**Causa**: `provideRoles()` no hace eager loading de `enabled_permissions`. Cada rol dispara una query adicional al iterar dentro del `flatMap` del resolver.

**Solución**: Cargar las relaciones en el método: `return $this->load('roles.enabled_permissions')->roles;`. Si tu fuente no es Eloquent, asegura que cada rol traiga sus permisos pre-resueltos.

[Volver al índice de documentación](../index.md)
