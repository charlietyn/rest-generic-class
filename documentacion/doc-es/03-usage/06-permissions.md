# Permisos: arquitectura, contratos y escenarios

> **Audiencia.** Esta guía está pensada para integradores del paquete que necesitan exponer los permisos efectivos del usuario autenticado (permisos directos ∪ permisos vía roles) y entender por qué el paquete obliga a declarar contratos formales en lugar de depender del nombre mágico de una relación Eloquent.
>
> **Pre-requisito conceptual.** Familiaridad con [`spatie/laravel-permission`](https://spatie.be/docs/laravel-permission), inyección de dependencias en el contenedor de Laravel y el principio de inversión de dependencias (DIP).

---

## 1. Motivación: el problema que resuelve esta arquitectura

Antes de la versión 3.0.0, el trait `HasReadableUserPermissions` resolvía los permisos vía rol con una expresión hardcodeada:

```php
// Versión legacy 2.x — acoplada al nombre 'roles'
return $this->loadMissing('roles', 'roles.enabled_permissions')
    ->roles->flatMap(fn($role) => $role->enabled_permissions)
    ->sort()->values();
```

Tres acoplamientos invisibles vivían en esas tres líneas:

1. **El string `'roles'`** dentro de `loadMissing(...)`: ningún código compilado lo verifica.
2. **El acceso mágico `$this->roles`**: presupone que el modelo `User` tiene una relación Eloquent llamada exactamente `roles`.
3. **El símbolo `enabled_permissions`** sobre cada rol: presupone que el modelo `Role` expone esa relación con ese nombre.

Si un integrador llamaba a su relación `groups`, tenía un wrapper `getRoles()` o resolvía los roles desde una fuente externa (LDAP, microservicio de identidad), tenía que reescribir el trait. La librería era flexible en concepto pero rígida en código.

A partir de 3.0.0 se introduce un par de **contratos formales** y un **resolver inyectable** que centraliza la resolución. La librería deja de saber *cómo* se cargan los roles; el integrador lo declara explícitamente en su modelo.

**Principios aplicados.** SOLID en sus cinco letras: el resolver tiene una sola responsabilidad (SRP), depende de abstracciones —las interfaces— y no de implementaciones concretas (DIP), las interfaces son pequeñas y enfocadas (ISP), cualquier modelo que las cumpla es sustituible (LSP), y agregar un nuevo origen de roles no requiere modificar la librería (OCP). Sumado a DRY (una única ruta de resolución) y KISS (un método fijo por interface, sin strings configurables ni reflection).

---

## 2. Mapa arquitectónico

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                         Tu aplicación Laravel                                │
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
│                     Ronu\RestGenericClass (paquete)                          │
│                                                                              │
│   ┌────────────────────────────────────────────────────────────────────┐     │
│   │  UserRolesResolver  (singleton)                                    │     │
│   │  + rolesOf($user): Collection                                       │     │
│   │  + permissionsViaRoles($user): Collection                           │     │
│   │  Lanza RolesContractViolationException si falta el contrato.       │     │
│   └────────────────┬───────────────────────────────────────────────────┘     │
│                    │ usa                                                     │
│                    ▼                                                         │
│   ┌────────────────────────────────────────────────────────────────────┐     │
│   │  HasReadableUserPermissions (trait)                                │     │
│   │  effectivePermissions() = direct ∪ permissionsViaRoles()            │     │
│   └────────────────────────────────────────────────────────────────────┘     │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Lectura del diagrama.** El paquete consume *abstracciones* (las dos interfaces). El integrador provee implementaciones concretas en sus modelos. El `UserRolesResolver` es la única puerta de entrada: cualquier consumidor —el trait `HasReadableUserPermissions`, un futuro middleware, un test— pasa por allí.

---

## 3. Componentes y su rol

| Componente | Tipo | Responsabilidad |
| --- | --- | --- |
| `ProvidesRoles` | Interface | Contrato del modelo User. Único método: `provideRoles(): Collection`. |
| `ProvidesRolePermissions` | Interface | Contrato del modelo Role. Único método: `provideRolePermissions(): Collection`. |
| `UserRolesResolver` | Clase singleton | Aplica los contratos y compone permisos efectivos. Punto único de resolución (DRY). |
| `RolesContractViolationException` | Excepción | Falla con mensaje accionable cuando un modelo no implementa la interface esperada. |
| `HasReadableUserPermissions` | Trait | Compone `direct ∪ via roles` — delega `via roles` al resolver. |
| `HasReadableRolePermissions` | Trait | Provee implementación por defecto de `provideRolePermissions()` — delegando a la relación `enabled_permissions` cuando existe. |

---

## 4. Anatomía del flujo `effectivePermissions`

```
1. RestController::get_authenticated_permissions()
        │
        ▼
2. $user instanceof ProvidesRoles ?  ── No → 500 con mensaje de migración
        │ Sí
        ▼
3. $user->permissionsPayload($request, $context)
        │
        ▼
4. effectivePermissions(?$guard)
        │
        ├──► $direct = $this->enabled_permissions()->get()      // permisos directos del User
        │
        └──► $via    = app(UserRolesResolver::class)
                          ->permissionsViaRoles($this)
                            │
                            ├──► $this->provideRoles()           // ← CONTRATO User
                            │
                            └──► foreach role:
                                    $role->provideRolePermissions()  // ← CONTRATO Role
                                    .flatMap → unique('id')
        │
        ▼
   $direct->concat($via)->unique('id')->values()
```

Cinco pasos. Cada paso depende de un único punto de extensión que el integrador controla.

---

## 5. Migración desde 2.x a 3.0.0

> **Breaking change.** El paquete deja de inferir el nombre de la relación de roles. Toda aplicación que actualice debe declarar las interfaces. La migración requiere ~5 líneas en dos archivos.

### Paso 1 — Modelo User

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
        // Reutiliza la relación Spatie 'roles' y eager-carga sus permisos.
        return $this->load('roles.enabled_permissions')->roles;
    }
}
```

### Paso 2 — Modelo Role

```php
use Ronu\RestGenericClass\Core\Models\SpatieRole;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions;
use Ronu\RestGenericClass\Core\Traits\HasReadableRolePermissions;

class Role extends SpatieRole implements ProvidesRolePermissions
{
    use HasReadableRolePermissions; // provee provideRolePermissions() por defecto
}
```

### Paso 3 (opcional) — fail-fast en boot

Declara los FQCN de tus modelos en `config/rest-generic-class.php` para que el `ServiceProvider` valide los contratos al arrancar la aplicación, no en el primer request a `/permissions`:

```php
// config/rest-generic-class.php
'permissions' => [
    'contracts' => [
        'user_model' => \App\Models\User::class,
        'role_model' => \App\Models\Role::class,
    ],
],
```

O por entorno:

```dotenv
REST_PERMISSIONS_USER_MODEL="App\Models\User"
REST_PERMISSIONS_ROLE_MODEL="App\Models\Role"
```

Si el FQCN no implementa la interface esperada, la app falla en `php artisan` con `RolesContractViolationException`.

---

## 6. Escenarios

> Cada escenario sigue el mismo formato del resto de la documentación: **Objetivo / Configuración / Pasos / Código / Notas / Errores comunes**.

### Escenario A — Integración estándar con Spatie

**Objetivo.** Reproducir el comportamiento clásico de Spatie (relación `roles` + `enabled_permissions`) con el nuevo contrato.

**Configuración.** Modelos `User` y `Role` migrados como en la sección 5. Sin overrides.

**Pasos.**
1. Aplicar las interfaces.
2. Llamar al endpoint autenticado.

**Código.**
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

**Notas.** El comportamiento es funcionalmente idéntico al de 2.x; lo que cambia es que el contrato es ahora *explícito*.

**Errores comunes.**
- Olvidar `implements ProvidesRoles` en el modelo User → respuesta `500` con mensaje de migración.
- Tener el `implements` pero no implementar el método → `Fatal error` al cargar la clase (lo detecta PHP, no la librería).

---

### Escenario B — Roles desde una relación con otro nombre

**Objetivo.** El modelo User llama a sus roles `groups`, no `roles` (legacy o convención de dominio).

**Configuración.**
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

**Pasos.**
1. Mantener tu relación con su nombre de dominio.
2. Implementar `provideRoles()` apuntando a esa relación.
3. Asegurar que el modelo `Group` también implementa `ProvidesRolePermissions`.

**Notas.** La librería nunca conoce el nombre `groups`. Solo conoce el contrato. El integrador conserva su modelo de dominio intacto.

**Errores comunes.**
- Que `Group` no implemente `ProvidesRolePermissions` → `RolesContractViolationException::roleMissingContract`.

---

### Escenario C — Roles calculados (no Eloquent)

**Objetivo.** Los roles del usuario no están en BD: vienen de una API de identidad federada y se cachean en Redis durante 5 minutos.

**Configuración.**
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

`IdentityClient::rolesFor()` devuelve una `Collection<Role>` donde cada `Role` es un Eloquent persistido localmente que implementa `ProvidesRolePermissions`.

**Pasos.**
1. Introducir un servicio externo de identidad.
2. Cachear el resultado.
3. Mantener los modelos `Role` locales como espejo (para que tengan permisos asociados).

**Notas.** Esta es la fuerza del contrato: la librería no sabe ni le importa que la fuente de roles sea remota. Solo recibe una `Collection` cuyos elementos cumplen el contrato.

**Errores comunes.**
- Devolver objetos planos (`stdClass`) en lugar de modelos que implementen el contrato → `RolesContractViolationException::roleMissingContract` en el primer rol.

---

### Escenario D — Multi-tenant: filtrar roles por tenant en `provideRoles()`

**Objetivo.** En una app multi-tenant, el mismo User puede tener distintos roles en distintos tenants. Solo deben contabilizarse los roles del tenant activo.

**Configuración.**
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

**Notas.** El filtro vive en el método contractual del modelo, no en la librería. Esto cumple el principio "una decisión de negocio, un dueño".

---

### Escenario E — Inyección del resolver en código propio

**Objetivo.** Un middleware de auditoría necesita registrar los roles efectivos de cada request sin tocar el endpoint de permisos.

**Configuración.**
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

**Notas.** Reutilizar el resolver garantiza que la auditoría aplique las mismas reglas de contrato que el endpoint público. **DRY.**

---

### Escenario F — Combinación con compresión wildcard

**Objetivo.** Reducir el payload del endpoint autenticado para una SPA con más de 200 permisos por usuario.

**Pasos.**
1. Solicitar `compress=true`.
2. Activar opcionalmente `expand=true` para diagnóstico.

**Código.**
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

**Notas.** La compresión es **solo de presentación**: jamás escribe wildcards en BD ni cambia las decisiones de autorización. Usa `compress_global=true` solo en clientes de auditoría con privilegios elevados.

---

## 7. Modos de fallo y diagnóstico

| Síntoma | Causa probable | Remedio |
| --- | --- | --- |
| Respuesta `500` con `"must implement ... ProvidesRoles"` | El modelo User no declara la interface. | Añadir `implements ProvidesRoles` y el método `provideRoles()`. |
| `RolesContractViolationException::roleMissingContract` en runtime | Un rol devuelto por `provideRoles()` no implementa `ProvidesRolePermissions`. | Añadir el `implements` en tu modelo Role o usar `HasReadableRolePermissions`. |
| `Fatal error: Class X contains 1 abstract method` al cargar la clase | El `implements` está pero falta el método. | Implementar el método con la firma exacta `public function provideRoles(): Collection`. |
| `php artisan` falla al arrancar con `RolesContractViolationException` | El FQCN declarado en `permissions.contracts.user_model` o `role_model` no implementa la interface. | Corregir la clase o eliminar la entrada en config si no aplica todavía. |
| N+1 al obtener permisos | `provideRoles()` no hace eager loading de `enabled_permissions`. | Llamar `$this->load('roles.enabled_permissions')` dentro del método. |

---

## 8. Pruebas recomendadas

```php
// tests/Feature/PermissionsContractTest.php
public function test_user_without_contract_returns_500()
{
    $user = new class extends Model {}; // no implementa ProvidesRoles
    $this->actingAs($user)
         ->getJson('/api/permissions')
         ->assertStatus(500)
         ->assertJsonFragment(['message' => fn ($v) => str_contains($v, 'ProvidesRoles')]);
}

public function test_resolver_throws_on_role_without_contract()
{
    $user = User::factory()->withRoles([new \stdClass()])->create(); // role inválido
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

## 9. Preguntas frecuentes específicas

**¿Por qué un método fijo (`provideRoles`) en lugar de un nombre configurable en `config()`?**
Porque un nombre en config es *stringly-typed*: el integrador puede escribir `roles_method => 'roless'` y el error sale en runtime profundo. Una interface es verificada por el cargador de clases de PHP y el resolver — fail-fast nativo, sin reflection ni magia.

**¿Por qué dos interfaces y no una sola que devuelva los permisos directamente?**
Porque `provideRoles()` y `provideRolePermissions()` responden a preguntas distintas. Existen consumidores que solo necesitan los roles (auditoría, UI de gestión) y otros que solo necesitan los permisos del rol (asignación masiva). Acoplarlas violaría ISP y reduciría la reusabilidad.

**¿Esto desacopla del paquete `spatie/laravel-permission`?**
Solo en la *resolución de roles*. Otras partes del paquete —el modelo `enabled_permissions` directo del User, los métodos `assignPermissionToRoles`/`assignPermissionToUsers`, la compresión wildcard— siguen apoyándose en `config('permission.*')` y en `PermissionRegistrar`. Desacoplar Spatie por completo es un proyecto distinto.

**¿Qué pasa si `provideRoles()` devuelve una colección vacía?**
`effectivePermissions()` devolverá únicamente los permisos directos del usuario. Es el camino feliz: el sistema funciona con usuarios sin roles asignados.

**¿Puedo registrar un `UserRolesResolver` propio?**
Sí. Está bindeado como singleton en el contenedor; basta con un `app()->extend(UserRolesResolver::class, ...)` en un service provider de la app. Útil para añadir telemetría o políticas de cache.

---

## 10. Referencias cruzadas

- [API pública](../04-reference/00-public-api.md#permisos-contratos-y-resolver)
- [Excepciones](../04-reference/04-exceptions.md#rolescontractviolationexception)
- [Solución de problemas](../05-quality/02-troubleshooting.md)
- [Variables de entorno](../02-configuration/01-env-vars.md)
- [Referencia de configuración](../02-configuration/00-configuration-reference.md)

[Volver al índice de documentación](../index.md)

## Evidencia

- Archivo: `src/Core/Support/Permissions/Contracts/ProvidesRoles.php`
  - Símbolo: interface `ProvidesRoles`
  - Notas: contrato del modelo User; método único `provideRoles(): Collection`.
- Archivo: `src/Core/Support/Permissions/Contracts/ProvidesRolePermissions.php`
  - Símbolo: interface `ProvidesRolePermissions`
  - Notas: contrato del modelo Role; método único `provideRolePermissions(): Collection`.
- Archivo: `src/Core/Support/Permissions/UserRolesResolver.php`
  - Símbolo: `UserRolesResolver::rolesOf()`, `permissionsViaRoles()`
  - Notas: punto único de resolución; aplica `instanceof` y lanza `RolesContractViolationException`.
- Archivo: `src/Core/Support/Permissions/Exceptions/RolesContractViolationException.php`
  - Símbolo: factories `userMissingContract`, `roleMissingContract`
  - Notas: mensajes accionables que indican exactamente qué clase debe implementar qué interface.
- Archivo: `src/Core/Traits/HasReadableUserPermissions.php`
  - Símbolo: `getEnabledPermissionsViaRoles()`, `effectivePermissions()`
  - Notas: delega la resolución vía-roles al `UserRolesResolver`.
- Archivo: `src/Core/Traits/HasReadableRolePermissions.php`
  - Símbolo: `provideRolePermissions()`
  - Notas: implementación por defecto que retorna `$this->enabled_permissions`.
- Archivo: `src/Core/Providers/RestGenericClassServiceProvider.php`
  - Símbolo: `register()`, `assertContractsIfDeclared()`
  - Notas: bind del resolver como singleton + validación temprana opcional cuando los FQCN están declarados en config.
- Archivo: `config/rest-generic-class.php`
  - Símbolo: bloque `permissions.contracts`
  - Notas: declaración opcional de `user_model` y `role_model` para fail-fast en boot.
