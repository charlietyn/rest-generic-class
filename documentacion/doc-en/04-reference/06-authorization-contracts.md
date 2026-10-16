# Authorization contracts (v2.9.0, default bindings of 3.0)

The CRUD core does not depend on any permission package. It authorizes through three small contracts in
`Ronu\RestGenericClass\Core\Contracts\Authorization` and Laravel's Gate, so any permission backend that
answers the Gate (spatie/laravel-permission, policies, a custom `Gate::before`, …) works.

| Contract | Used by | Default binding |
| --- | --- | --- |
| `AuthorizationContext::current(?Request): ?AuthorizationScope` | relation checks | `NullAuthorizationContext` (no scope: nothing is checked). [`ronu/rest-permissions`](https://github.com/charlietyn/rest-permissions) binds `RouteAuthorizationContext`, which exposes what its `SpatieAuthorize` middleware granted. |
| `RelationAuthorizer::enabled()` / `filter(Model, array $relations, ?callable $pathOf)` | `BaseService` (`?relations=`, before the cache lookup) and the relation endpoints | `GateRelationAuthorizer` |
| `FieldWriteAuthorizer::deniedFields(Model, mixed $user): list<string>` | `BaseModel::getDeniedFieldsForUser()` → `FilterRequestByRole` | `RoleFieldWriteAuthorizer` (`$fieldsByRole`, `hasRole()` when the user has it) |

`AuthorizationScope` carries `guard`, `action` (e.g. `admin.crm.clients.index`) and `resource`
(`admin.crm.clients`). A request without a scope is not checked; a scope without a resource denies.

## GateRelationAuthorizer

With `authorization.eager_relations=require_read`, each to-many hop of a requested relation path needs any
ability returned by `RelationAbilityNamer` — by default `<resource>.<path up to the hop>.read` or `….manage`
(suffixes in `authorization.relation_abilities`). It asks `Gate::forUser($user)->any($abilities)`, with the
user of the scope's guard. To-one hops are exempt while `eager_relations_to_one=allow`. A denied relation is
a 403 with `filtering.strict_relations=true` and is left out otherwise.

**Fail closed.** If `require_read` is configured but `AuthorizationContext` is `NullAuthorizationContext`
(nothing provides the request scope), the core throws `MissingAuthorizationContextException`: at boot for
HTTP requests, and at first use in the console (so `composer install` / `package:discover` keep working).

## Replacing a piece

Bind your implementation in a service provider. The core registers its defaults with `bindIf`/`singletonIf`,
so your binding wins whatever the provider order:

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

// Another ability convention: "relations:<path>".
$this->app->instance(\Ronu\RestGenericClass\Core\Authorization\RelationAbilityNamer::class, new class extends \Ronu\RestGenericClass\Core\Authorization\RelationAbilityNamer {
    public function abilities(string $resource, array $hops): array { return ['relations:' . implode('.', $hops)]; }
});
```

Outside Laravel (Capsule), `Authorizers::relations()/fields()/context()` fall back to the defaults.

## Configuration

`rest-generic-class.authorization.*` (`eager_relations`, `eager_relations_to_one`, `relation_abilities`). A
`null` value falls back to the legacy key `rest-generic-class.permissions.authorization.*` of the same name,
then to the default. See the [configuration reference](../02-configuration/00-configuration-reference.md#core-authorization-v290).

[Back to documentation index](../index.md)
