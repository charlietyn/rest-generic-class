# Troubleshooting

## "Relation 'x' is not allowed"

**Cause**: The relation is not listed in `const RELATIONS` and `filtering.strict_relations` is enabled.

**Fix**: Add the relation to `RELATIONS` on the model or disable strict mode in config (not recommended).

---

## "Maximum nesting depth" / "Maximum conditions" errors

**Cause**: `oper` exceeded `filtering.max_depth` or `filtering.max_conditions`.

**Fix**: Reduce filter complexity or increase limits in `config/rest-generic-class.php`.

---

## "Invalid hierarchy mode" or hierarchy not supported

**Cause**: Invalid `hierarchy.filter_mode` or missing `HIERARCHY_FIELD_ID` on the model.

**Fix**: Use a valid mode (`match_only`, `with_ancestors`, `with_descendants`, `full_branch`, `root_filter`) and define the hierarchy field in the model.

---

## Export methods fail

**Cause**: `exportExcel()` or `exportPdf()` are called without installing optional packages.

**Fix**: Install `maatwebsite/excel` and/or `barryvdh/laravel-dompdf`.

---

## Spatie authorization fails unexpectedly

**Cause**: Permission cache not refreshed or tenant/guard mismatch.

**Fix**: Clear Spatie permission cache and ensure guard/team ID is set before authorization.

---

## "The authenticated user model must implement ... ProvidesRoles" (HTTP 500)

**Cause**: The `User` model does not declare `implements ProvidesRoles`. From 3.0.0 the library requires this contract to resolve role-based permissions.

**Fix**: Add the `implements` clause and the `provideRoles()` method. Minimal example:

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

See the [permissions guide](../03-usage/06-permissions.md#5-migrating-from-2x-to-300).

---

## `RolesContractViolationException::roleMissingContract` at runtime

**Cause**: `provideRoles()` returned one or more objects that do not implement `ProvidesRolePermissions` (e.g., `stdClass`, a legacy model, or a `Role` missing the `implements` clause).

**Fix**: Make sure every element returned by `provideRoles()` is an instance of a `Role` model that implements the interface. If you extend `SpatieRole` and use `HasReadableRolePermissions`, it is enough to add `implements ProvidesRolePermissions`.

---

## The app fails to boot with `RolesContractViolationException`

**Cause**: You declared `permissions.contracts.user_model` or `permissions.contracts.role_model` in `config/rest-generic-class.php` pointing to a class that does not implement the expected interface. That validation runs in `RestGenericClassServiceProvider::boot()`.

**Fix**: Either implement the contract in the declared class or remove the config entry until the model is ready.

---

## N+1 queries when calling `/api/permissions`

**Cause**: `provideRoles()` does not eager-load `enabled_permissions`. Every role then triggers an extra query inside the resolver's `flatMap`.

**Fix**: Load the relations inside the method: `return $this->load('roles.enabled_permissions')->roles;`. If your source is not Eloquent, ensure each role carries its permissions pre-resolved.

[Back to documentation index](../index.md)
