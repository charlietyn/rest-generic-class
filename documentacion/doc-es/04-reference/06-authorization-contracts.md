# Contratos de autorización (v2.9.0)

El núcleo CRUD no depende de ningún paquete de permisos. Autoriza mediante tres contratos pequeños de
`Ronu\RestGenericClass\Core\Contracts\Authorization` y el Gate de Laravel, así que funciona con cualquier
backend que responda al Gate (spatie/laravel-permission, policies, un `Gate::before` propio, …).

| Contrato | Lo usa | Binding por defecto |
| --- | --- | --- |
| `AuthorizationContext::current(?Request): ?AuthorizationScope` | las comprobaciones de relaciones | los permisos por rutas de este paquete (`RouteAuthorizationContext`, lee lo que concedió `SpatieAuthorize`). `NullAuthorizationContext` no informa ningún ámbito. |
| `RelationAuthorizer::enabled()` / `filter(Model, array $relations, ?callable $pathOf)` | `BaseService` (`?relations=`, antes de leer la caché) y los endpoints de relaciones | `GateRelationAuthorizer` |
| `FieldWriteAuthorizer::deniedFields(Model, mixed $user): list<string>` | `BaseModel::getDeniedFieldsForUser()` → `FilterRequestByRole` | `RoleFieldWriteAuthorizer` (`$fieldsByRole`, con `hasRole()` si el usuario lo tiene) |

`AuthorizationScope` lleva `guard`, `action` (p. ej. `admin.crm.clients.index`) y `resource`
(`admin.crm.clients`). Una petición sin ámbito no se comprueba; un ámbito sin recurso deniega.

## GateRelationAuthorizer

Con `authorization.eager_relations=require_read`, cada salto a-muchos de una relación pedida necesita
alguna de las abilities que devuelve `RelationAbilityNamer`: por defecto `<recurso>.<ruta hasta el salto>.read`
o `….manage` (sufijos en `authorization.relation_abilities`). Pregunta a `Gate::forUser($user)->any($abilities)`
con el usuario del guard del ámbito. Los saltos a-uno quedan exentos con `eager_relations_to_one=allow`. Una
relación denegada es un 403 con `filtering.strict_relations=true` y, si no, se omite.

**Cierre por defecto.** Si se configura `require_read` pero `AuthorizationContext` es `NullAuthorizationContext`
(nada aporta el ámbito de la petición), el núcleo lanza `MissingAuthorizationContextException`: al arrancar en
peticiones HTTP, y en el primer uso en consola (así `composer install` y `package:discover` siguen funcionando).

## Sustituir una pieza

Registra tu implementación en un service provider. El núcleo registra sus valores por defecto con
`bindIf`/`singletonIf`, así que tu binding gana sea cual sea el orden de los providers:

```php
use Ronu\RestGenericClass\Core\Authorization\AuthorizationScope;
use Ronu\RestGenericClass\Core\Contracts\Authorization\AuthorizationContext;

$this->app->bind(AuthorizationContext::class, fn () => new class implements AuthorizationContext {
    public function current(?\Illuminate\Http\Request $request = null): ?AuthorizationScope
    {
        $route = ($request ?? request())->route();
        return $route?->getName() ? new AuthorizationScope('api', $route->getName(), \Illuminate\Support\Str::beforeLast($route->getName(), '.')) : null;
    }
});

// Otra convención de nombres: "relations:<ruta>".
$this->app->instance(\Ronu\RestGenericClass\Core\Authorization\RelationAbilityNamer::class, new class extends \Ronu\RestGenericClass\Core\Authorization\RelationAbilityNamer {
    public function abilities(string $resource, array $hops): array { return ['relations:' . implode('.', $hops)]; }
});
```

Fuera de Laravel (Capsule), `Authorizers::relations()/fields()/context()` recurren a los valores por defecto.

## Configuración

`rest-generic-class.authorization.*` (`eager_relations`, `eager_relations_to_one`, `relation_abilities`). Un
valor `null` recurre a la clave antigua `rest-generic-class.permissions.authorization.*` del mismo nombre y
después al valor por defecto. Ver la [referencia de configuración](../02-configuration/00-configuration-reference.md#autorización-del-núcleo-v290).

[Volver al índice de la documentación](../index.md)
