# Permissions: architecture, contracts, and scenarios

> **Audience.** This guide is for integrators who need to expose the authenticated user's effective permissions (direct ∪ via roles) and want to understand why the package now requires formal contracts instead of relying on the magic name of an Eloquent relation.
>
> **Conceptual prerequisite.** Familiarity with [`spatie/laravel-permission`](https://spatie.be/docs/laravel-permission), Laravel's service container, and the Dependency Inversion Principle (DIP).

---

## 1. Motivation: the problem this architecture solves

Before version 3.0.0, the trait `HasReadableUserPermissions` resolved role-based permissions with a hardcoded expression:

```php
// Legacy 2.x — coupled to the literal name 'roles'
return $this->loadMissing('roles', 'roles.enabled_permissions')
    ->roles->flatMap(fn($role) => $role->enabled_permissions)
    ->sort()->values();
```

Three invisible couplings lived in those three lines:

1. **The `'roles'` string** inside `loadMissing(...)`: no compiled code verifies it.
2. **The magic accessor `$this->roles`**: assumes the User model has an Eloquent relation literally named `roles`.
3. **The `enabled_permissions` symbol** on each role: assumes the Role model exposes that relation under that exact name.

If an integrator named the relation `groups`, wrapped it in `getRoles()`, or pulled roles from an external identity source (LDAP, an identity microservice), they had to rewrite the trait. The library was flexible in concept but rigid in code.

Starting in 3.0.0, a pair of **formal contracts** plus an **injectable resolver** centralize the resolution. The library no longer knows *how* roles are loaded; the integrator declares it explicitly on their model.

**Principles applied.** SOLID across the board: the resolver has a single responsibility (SRP), depends on abstractions —the interfaces— rather than concrete implementations (DIP), the interfaces are small and focused (ISP), any model that satisfies them is substitutable (LSP), and adding a new role source requires no change inside the library (OCP). On top of that, DRY (one resolution path) and KISS (a fixed method per interface).

> **Note (≥ 2.2.x).** The `provideRoles()` contract remains the extension point, but the `HasReadableUserPermissions` trait now offers an **opt-in default implementation**: you declare the relation name via `const ROLES_RELATION` (or the `roles_relation` config) and the trait resolves it and normalizes the cardinality for you, without writing `provideRoles()`. The explicit override stays the "pure" path for non-trivial sources. See [Scenario B-bis](#scenario-b-bis--configurable-relation-without-writing-provideroles).

---

## 2. Architectural map

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                         Your Laravel application                             │
│                                                                              │
│   ┌─────────────────────────┐         ┌──────────────────────────────┐       │
│   │  App\Models\User        │         │  App\Models\Role             │       │
│   │  implements ProvidesRoles│        │  implements ProvidesRolePermissions│  │
│   │                         │         │                              │       │
│   │  + provideRoles(): Coll │ ──────► │  + provideRolePermissions(): │       │
│   │                         │         │      Collection              │       │
│   └────────────┬────────────┘         └──────────────┬───────────────┘       │
└────────────────┼──────────────────────────────────────┼───────────────────────┘
                 │                                      │
                 ▼                                      ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                     Ronu\RestGenericClass (package)                          │
│                                                                              │
│   ┌────────────────────────────────────────────────────────────────────┐     │
│   │  UserRolesResolver  (singleton)                                    │     │
│   │  + rolesOf($user): Collection                                       │     │
│   │  + permissionsViaRoles($user): Collection                           │     │
│   │  Throws RolesContractViolationException if a contract is missing.  │     │
│   └────────────────┬───────────────────────────────────────────────────┘     │
│                    │ used by                                                 │
│                    ▼                                                         │
│   ┌────────────────────────────────────────────────────────────────────┐     │
│   │  HasReadableUserPermissions (trait)                                │     │
│   │  effectivePermissions() = direct ∪ permissionsViaRoles()            │     │
│   └────────────────────────────────────────────────────────────────────┘     │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Reading the diagram.** The package consumes *abstractions* (the two interfaces). The integrator provides concrete implementations on their models. The `UserRolesResolver` is the single entry point: every consumer —the `HasReadableUserPermissions` trait, a future middleware, a test— goes through it.

---

## 3. Components and their roles

| Component | Type | Responsibility |
| --- | --- | --- |
| `ProvidesRoles` | Interface | User model contract. Single method: `provideRoles(): Collection`. |
| `ProvidesRolePermissions` | Interface | Role model contract. Single method: `provideRolePermissions(): Collection`. |
| `UserRolesResolver` | Singleton class | Enforces the contracts and composes effective permissions. Single resolution path (DRY). |
| `RolesContractViolationException` | Exception | Fails with an actionable message when a model misses the expected interface. |
| `HasReadableUserPermissions` | Trait | Composes `direct ∪ via roles` — delegates `via roles` to the resolver. |
| `HasReadableRolePermissions` | Trait | Provides a default implementation of `provideRolePermissions()` that returns `$this->enabled_permissions`. |

---

## 4. Anatomy of the `effectivePermissions` flow

```
1. RestController::get_authenticated_permissions()
        │
        ▼
2. $user instanceof ProvidesRoles ?  ── No → 500 with migration message
        │ Yes
        ▼
3. $user->permissionsPayload($request, $context)
        │
        ▼
4. effectivePermissions(?$guard)
        │
        ├──► $direct = $this->enabled_permissions()->get()      // user's direct permissions
        │
        └──► $via    = app(UserRolesResolver::class)
                          ->permissionsViaRoles($this)
                            │
                            ├──► $this->provideRoles()           // ← User CONTRACT
                            │
                            └──► foreach role:
                                    $role->provideRolePermissions()  // ← Role CONTRACT
                                    .flatMap → unique('id')
        │
        ▼
   $direct->concat($via)->unique('id')->values()
```

Five steps. Each step depends on a single extension point that the integrator owns.

---

## 5. Migrating from 2.x to 3.0.0

> **Breaking change.** The package no longer infers the name of the roles relation. Any application upgrading must declare the interfaces. The migration is roughly five lines spread across two files.

### Step 1 — User model

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles;
use Ronu\RestGenericClass\Core\Traits\HasReadableUserPermissions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements ProvidesRoles
{
    use HasRoles, HasReadableUserPermissions;

    public function provideRoles(): Collection
    {
        // Reuse the Spatie 'roles' relation and eager-load its permissions.
        return $this->load('roles.enabled_permissions')->roles;
    }
}
```

### Step 2 — Role model

```php
use Ronu\RestGenericClass\Core\Models\SpatieRole;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions;
use Ronu\RestGenericClass\Core\Traits\HasReadableRolePermissions;

class Role extends SpatieRole implements ProvidesRolePermissions
{
    use HasReadableRolePermissions; // provides provideRolePermissions() by default
}
```

### Step 3 (optional) — fail-fast on boot

Declare your model FQCNs in `config/rest-generic-class.php` so the `ServiceProvider` validates the contracts at boot time, not on the first request to `/permissions`:

```php
// config/rest-generic-class.php
'permissions' => [
    'contracts' => [
        'user_model' => \App\Models\User::class,
        'role_model' => \App\Models\Role::class,
    ],
],
```

Or via environment variables:

```dotenv
REST_PERMISSIONS_USER_MODEL="App\Models\User"
REST_PERMISSIONS_ROLE_MODEL="App\Models\Role"
```

If the FQCN does not implement the expected interface, the app fails on `php artisan` startup with a `RolesContractViolationException`.

---

## 6. Scenarios

> Every scenario follows the same structure used in the rest of this documentation: **Goal / Setup / Steps / Code / Notes / Common pitfalls**.

### Scenario A — Standard Spatie integration

**Goal.** Reproduce the classic Spatie behavior (the `roles` relation + `enabled_permissions`) under the new contract.

**Setup.** `User` and `Role` migrated as in section 5. No overrides.

**Steps.**
1. Apply the interfaces.
2. Hit the authenticated endpoint.

**Code.**
```http
GET /api/permissions
Authorization: Bearer <token>
```
```json
{
  "ok": true,
  "data": {
    "user": {"id": 10, "email": "admin@example.com", "name": "Admin"},
    "count": 18,
    "permissions": [
      {"id": 1, "name": "security.user.index", "module": "security", "guard": "api"},
      "..."
    ]
  }
}
```

**Notes.** Behaviorally identical to 2.x; what changes is that the contract is now *explicit*.

**Common pitfalls.**
- Forgetting `implements ProvidesRoles` on the User → `500` response with migration message.
- Declaring `implements` but failing to define the method → `Fatal error` at class load time (PHP catches it, not the library).

---

### Scenario B — Roles in a relation with a different name

**Goal.** The User model calls its roles `groups`, not `roles` (legacy naming or domain convention).

**Setup.**
```php
class User extends Authenticatable implements ProvidesRoles
{
    use HasReadableUserPermissions;

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->using(GroupUser::class);
    }

    public function provideRoles(): Collection
    {
        return $this->load('groups.enabled_permissions')->groups;
    }
}
```

**Steps.**
1. Keep your relation under its domain name.
2. Implement `provideRoles()` pointing at it.
3. Make sure the `Group` model also implements `ProvidesRolePermissions`.

**Notes.** The library never knows about the name `groups`. It only knows the contract. The integrator's domain model stays untouched.

**Common pitfalls.**
- `Group` not implementing `ProvidesRolePermissions` → `RolesContractViolationException::roleMissingContract`.

---

### Scenario B-bis — Configurable relation without writing `provideRoles()`

**Goal.** Achieve the same as Scenario B (relation under a different name, e.g. `array_role` in
generated models) **without** writing the body of `provideRoles()` on every model.

**How it works.** The `HasReadableUserPermissions` trait now ships a **default** implementation of
`provideRoles()`. It resolves the relation name with this priority:

1. Per-model constant `ROLES_RELATION` (most specific).
2. Global config `rest-generic-class.permissions.roles_relation` (env `REST_PERMISSIONS_ROLES_RELATION`).
3. Default `'roles'` (backward compatible).

It then eager-loads `<relation>.enabled_permissions` and **normalizes the cardinality** into a `Collection`.

**Setup (declarative).**
```php
class User extends Authenticatable implements ProvidesRoles
{
    use HasReadableUserPermissions;

    // Just declare the relation name; no provideRoles() needed.
    const ROLES_RELATION = 'array_role';

    public function array_role(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_users', 'user_id', 'role_id');
    }
}
```

**Cardinality support.** The same default implementation covers both data models:

| Role↔User cardinality | Eloquent relation | Returns | Normalization |
| --- | --- | --- | --- |
| Many-to-many (user with several roles) | `BelongsToMany` / `HasMany` | `Collection<Role>` | used as-is (nulls filtered) |
| One-to-many (user with a single role) | `BelongsTo` / `HasOne` | single `Role` model or `null` | wrapped into a 1-item `Collection` (or empty) |

```php
// One-to-many example: each user belongs to a single role via role_id.
class User extends Authenticatable implements ProvidesRoles
{
    use HasReadableUserPermissions;

    const ROLES_RELATION = 'role';

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
```

**Steps.**
1. Declare `const ROLES_RELATION` (or the global config) with your relation name.
2. Make sure the Role model implements `ProvidesRolePermissions`.
3. Done — no `provideRoles()` required.

**Notes.** `implements ProvidesRoles` is still required (the `UserRolesResolver` checks `instanceof`).
For custom logic (external source, computed roles, tenant filtering), **write your own `provideRoles()`**:
a class method always wins over the trait default — that is the path of Scenarios C and D.

**Common pitfalls.**
- Misconfigured relation name or missing relation → `RolesContractViolationException::missingRolesRelation`, telling you the looked-up name and how to fix it.

---

### Scenario C — Computed roles (non-Eloquent)

**Goal.** The user's roles are not stored in the DB: they come from a federated identity API and are cached in Redis for 5 minutes.

**Setup.**
```php
class User extends Authenticatable implements ProvidesRoles
{
    use HasReadableUserPermissions;

    public function provideRoles(): Collection
    {
        return Cache::remember(
            "user.{$this->id}.federated_roles",
            now()->addMinutes(5),
            fn () => app(IdentityClient::class)->rolesFor($this->id)
        );
    }
}
```

`IdentityClient::rolesFor()` returns a `Collection<Role>` where each `Role` is a locally persisted Eloquent model that implements `ProvidesRolePermissions`.

**Steps.**
1. Introduce an external identity service.
2. Cache the result.
3. Keep local `Role` models as a mirror (so they can carry permissions).

**Notes.** This is the strength of the contract: the library does not know nor care that the source of roles is remote. It just receives a `Collection` whose elements honor the contract.

**Common pitfalls.**
- Returning plain objects (`stdClass`) instead of contract-bearing models → `RolesContractViolationException::roleMissingContract` on the first role.

---

### Scenario D — Multi-tenant: filter roles by tenant inside `provideRoles()`

**Goal.** In a multi-tenant app, the same User can have different roles under different tenants. Only the active tenant's roles must be counted.

**Setup.**
```php
class User extends Authenticatable implements ProvidesRoles
{
    use HasReadableUserPermissions;

    public function provideRoles(): Collection
    {
        $tenantId = app('current_tenant')->id;

        return $this->load(['roles' => fn ($q) => $q->where('tenant_id', $tenantId)])
            ->roles
            ->load('enabled_permissions');
    }
}
```

**Notes.** The filter lives in the contractual method on the model, not inside the library. This honors the rule "one business decision, one owner."

---

### Scenario E — Injecting the resolver into your own code

**Goal.** An audit middleware needs to log each request's effective roles without touching the permissions endpoint.

**Setup.**
```php
use Ronu\RestGenericClass\Core\Support\Permissions\UserRolesResolver;

class AuditUserRoles
{
    public function __construct(private UserRolesResolver $resolver) {}

    public function handle(Request $request, Closure $next)
    {
        if ($user = $request->user()) {
            Log::channel('audit')->info('roles', [
                'user_id' => $user->getKey(),
                'roles'   => $this->resolver->rolesOf($user)->pluck('name'),
            ]);
        }
        return $next($request);
    }
}
```

**Notes.** Reusing the resolver guarantees the audit applies the same contract rules as the public endpoint. **DRY.**

---

### Scenario F — Combining with wildcard compression

**Goal.** Shrink the authenticated endpoint payload for an SPA where users have over 200 permissions.

**Steps.**
1. Request `compress=true`.
2. Optionally enable `expand=true` for diagnostics.

**Code.**
```http
GET /api/permissions?guard=api&compress=true&expand=true
```
```json
{
  "ok": true,
  "data": {
    "user": {"id": 10, "email": "admin@example.com", "name": "Admin"},
    "guard": "api",
    "permissions": ["security.*", "sales.order.*"],
    "stats": {"original_count": 217, "compressed_count": 2, "compression_ratio": 108},
    "expanded": ["security.user.index", "security.user.show", "..."]
  }
}
```

**Notes.** Compression is **presentation-only**: it never writes wildcards to the DB and never alters authorization decisions. Use `compress_global=true` only with audit clients holding elevated privileges.

---

## 7. Failure modes and diagnostics

| Symptom | Likely cause | Remedy |
| --- | --- | --- |
| `500` response with `"must implement ... ProvidesRoles"` | The User model does not declare the interface. | Add `implements ProvidesRoles` and the `provideRoles()` method. |
| `RolesContractViolationException::roleMissingContract` at runtime | A role returned by `provideRoles()` does not implement `ProvidesRolePermissions`. | Add `implements` on your Role model or `use HasReadableRolePermissions`. |
| `Fatal error: Class X contains 1 abstract method` at class load | `implements` is present but the method is missing. | Implement the method with the exact signature `public function provideRoles(): Collection`. |
| `php artisan` fails on boot with `RolesContractViolationException` | The FQCN declared under `permissions.contracts.user_model` or `role_model` does not implement the interface. | Fix the class or remove the config entry if it does not apply yet. |
| N+1 queries when reading permissions | `provideRoles()` skips eager loading of `enabled_permissions`. | Call `$this->load('roles.enabled_permissions')` inside the method. |

---

## 8. Recommended tests

```php
// tests/Feature/PermissionsContractTest.php
public function test_user_without_contract_returns_500()
{
    $user = new class extends Model {}; // does not implement ProvidesRoles
    $this->actingAs($user)
         ->getJson('/api/permissions')
         ->assertStatus(500)
         ->assertJsonFragment(['message' => fn ($v) => str_contains($v, 'ProvidesRoles')]);
}

public function test_resolver_throws_on_role_without_contract()
{
    $user = User::factory()->withRoles([new \stdClass()])->create(); // invalid role
    $this->expectException(RolesContractViolationException::class);
    app(UserRolesResolver::class)->permissionsViaRoles($user);
}

public function test_effective_permissions_equal_direct_union_via_roles()
{
    [$user, $role] = [User::factory()->create(), Role::factory()->create()];
    $direct = Permission::factory()->count(2)->create();
    $viaRole = Permission::factory()->count(3)->create();
    $user->givePermissionTo($direct);
    $role->givePermissionTo($viaRole);
    $user->assignRole($role);

    $this->assertEqualsCanonicalizing(
        $direct->concat($viaRole)->pluck('id')->all(),
        $user->effectivePermissions()->pluck('id')->all()
    );
}
```

---

## 9. Specific FAQ

**Why a fixed method (`provideRoles`) instead of a configurable name in `config()`?**
Because a config name is *stringly-typed*: an integrator can write `roles_method => 'roless'` and the error surfaces deep in runtime. An interface is verified by PHP's class loader and the resolver — native fail-fast, no reflection, no magic.

**Why two interfaces and not a single one returning permissions directly?**
Because `provideRoles()` and `provideRolePermissions()` answer different questions. Some consumers only need roles (audit, management UI); others only need a role's permissions (bulk assignment). Coupling them would violate ISP and reduce reuse.

**Does this decouple from `spatie/laravel-permission`?**
Only on the *role resolution side*. Other parts of the package —the User's direct `enabled_permissions` model, `assignPermissionToRoles`/`assignPermissionToUsers`, wildcard compression— still rely on `config('permission.*')` and `PermissionRegistrar`. Fully decoupling Spatie is a separate project.

**What if `provideRoles()` returns an empty collection?**
`effectivePermissions()` returns only the user's direct permissions. That is the happy path: the system handles users with no role assignments.

**Can I register my own `UserRolesResolver`?**
Yes. It's bound as a singleton in the container; just call `app()->extend(UserRolesResolver::class, ...)` from your application's service provider. Useful to add telemetry or caching policies.

---

## 10. Cross references

- [Public API](../04-reference/00-public-api.md#permissions-contracts-and-resolver)
- [Exceptions](../04-reference/04-exceptions.md#rolescontractviolationexception)
- [Troubleshooting](../05-quality/02-troubleshooting.md)
- [Environment variables](../02-configuration/01-env-vars.md)
- [Configuration reference](../02-configuration/00-configuration-reference.md)

[Back to documentation index](../index.md)

## Evidence

- File: `src/Core/Support/Permissions/Contracts/ProvidesRoles.php`
  - Symbol: interface `ProvidesRoles`
  - Notes: User model contract; sole method `provideRoles(): Collection`.
- File: `src/Core/Support/Permissions/Contracts/ProvidesRolePermissions.php`
  - Symbol: interface `ProvidesRolePermissions`
  - Notes: Role model contract; sole method `provideRolePermissions(): Collection`.
- File: `src/Core/Support/Permissions/UserRolesResolver.php`
  - Symbol: `UserRolesResolver::rolesOf()`, `permissionsViaRoles()`
  - Notes: single resolution point; performs `instanceof` and throws `RolesContractViolationException`.
- File: `src/Core/Support/Permissions/Exceptions/RolesContractViolationException.php`
  - Symbol: factories `userMissingContract`, `roleMissingContract`
  - Notes: actionable messages stating exactly which class must implement which interface.
- File: `src/Core/Traits/HasReadableUserPermissions.php`
  - Symbol: `getEnabledPermissionsViaRoles()`, `effectivePermissions()`
  - Notes: delegates via-roles resolution to `UserRolesResolver`.
- File: `src/Core/Traits/HasReadableRolePermissions.php`
  - Symbol: `provideRolePermissions()`
  - Notes: default implementation that returns `$this->enabled_permissions`.
- File: `src/Core/Providers/RestGenericClassServiceProvider.php`
  - Symbol: `register()`, `assertContractsIfDeclared()`
  - Notes: binds the resolver as a singleton plus optional early validation when FQCNs are declared in config.
- File: `config/rest-generic-class.php`
  - Symbol: `permissions.contracts` block
  - Notes: optional declaration of `user_model` and `role_model` for boot-time fail-fast.
