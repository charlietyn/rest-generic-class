# Reglas de Validación y Sistema de Validación en Base de Datos

Este paquete incluye un sistema completo de validación de IDs y arrays contra la base de datos, compuesto por seis reglas de validación personalizadas y un trait reutilizable. Todas las reglas implementan `ValidationRule` y `ValidatorAwareRule` de Laravel 12 para una integración nativa con el sistema de validación.

---

## Arquitectura general

```
BaseFormRequest
    └─ ValidatesExistenceInDatabase (trait)
           ├─ validateIdsExistInTable()
           ├─ validateIdsExistWithStatus()
           ├─ validateIdsExistNotDeleted()
           ├─ validateIdsExistWithAnyStatus()
           ├─ validateIdsExistWithDateRange()
           ├─ validateIdsWithCustomQuery()
           └─ getMissingIds()

Reglas de validación (usan el mismo trait internamente):
    ├─ IdsExistInTable
    ├─ IdsExistNotDelete
    ├─ IdsExistWithAnyStatus
    ├─ IdsExistWithDateRange
    ├─ IdsWithCustomQuery
    └─ ArrayCount
```

Todas las reglas de IDs operan sobre **arrays**, no sobre valores únicos. Si el valor no es un array o está vacío, la validación se considera exitosa (pass-through defensivo). Internamente aprovechan el caché de Laravel con un TTL de 1 hora (configurable) para evitar golpear la base de datos en cada request.

---

## Trait `ValidatesExistenceInDatabase`

Namespace: `Ronu\RestGenericClass\Core\Traits\ValidatesExistenceInDatabase`

El trait es la pieza central del sistema. Puede usarse directamente en cualquier `FormRequest` o clase de servicio. Ya está incluido en `BaseFormRequest`.

### Propiedades configurables

Todas las propiedades se inicializan desde la sección de configuración `rest-generic-class.validation` (que lee del `.env`). Puedes sobreescribirlas por clase asignando la propiedad directamente, o globalmente mediante variables de entorno.

| Propiedad | Tipo | Clave de config | Variable de entorno | Default | Descripción |
|---|---|---|---|---|---|
| `$validationCacheTtl` | `int` | `validation.cache_ttl` | `REST_VALIDATION_CACHE_TTL` | `3600` | TTL del caché en segundos |
| `$enableValidationCache` | `bool` | `validation.cache_enabled` | `REST_VALIDATION_CACHE_ENABLED` | `true` | Activa/desactiva caché de validación |
| `$cacheKeyPrefix` | `string` | `validation.cache_prefix` | `REST_VALIDATION_CACHE_PREFIX` | `'validation'` | Prefijo de las claves de caché |
| `$connection` | `string` | `validation.connection` | `REST_VALIDATION_CONNECTION` | `'db'` | Nombre de la conexión de BD |

**Orden de prioridad:** propiedad asignada en la clase > valor de config (desde `.env`) > valor por defecto hardcodeado.

```env
# Ejemplo de .env
REST_VALIDATION_CACHE_ENABLED=true
REST_VALIDATION_CACHE_TTL=1800
REST_VALIDATION_CACHE_PREFIX=validation
REST_VALIDATION_CONNECTION=mysql
```

### Métodos principales

#### `validateIdsExistInTable(array $ids, string $table, string $column = 'id', array $additionalConditions = []): array|bool`

Verifica que todos los IDs existan en la tabla con condiciones opcionales. Devuelve un array asociativo:

```php
[
    'success'      => bool,
    'missing_ids'  => array,
    'existing_ids' => array,
]
```

```php
// Verificación simple
$result = $this->validateIdsExistInTable([1, 2, 3], 'users');

// Con condición extra (solo usuarios activos)
$result = $this->validateIdsExistInTable([1, 2], 'users', 'id', ['status' => 'active']);

// Múltiples condiciones
$result = $this->validateIdsExistInTable([1, 2], 'users', 'id', [
    'status'   => 'active',
    'verified' => true,
]);

if (!$result['success']) {
    // IDs que no existen: $result['missing_ids']
}
```

#### `validateIdsExistWithStatus(array $ids, string $table, string $status = 'active', string $statusColumn = 'status'): array|bool`

Atajo para validar IDs con un status específico.

```php
// Roles activos
$this->validateIdsExistWithStatus([1, 2], 'roles');

// Posts publicados con columna personalizada
$this->validateIdsExistWithStatus([10, 20], 'posts', 'published', 'state');
```

#### `validateIdsExistNotDeleted(array $ids, string $table, string $column = 'id'): bool`

Excluye registros con `deleted_at IS NOT NULL` (soft deletes).

```php
$valid = $this->validateIdsExistNotDeleted([1, 2, 3], 'departments');
```

#### `validateIdsExistWithAnyStatus(array $ids, string $table, array $statuses, string $statusColumn = 'status'): bool`

Acepta IDs que tengan cualquiera de los statuses especificados (condición OR).

```php
// Usuarios 'active' O 'pending'
$valid = $this->validateIdsExistWithAnyStatus([1, 2], 'users', ['active', 'pending']);
```

#### `validateIdsExistWithDateRange(array $ids, string $table, string $dateColumn, ?string $startDate = null, ?string $endDate = null, array $additionalConditions = []): bool`

Valida que los IDs existan dentro de un rango de fechas. Ambos extremos son opcionales.

```php
$valid = $this->validateIdsExistWithDateRange(
    [1, 2, 3],
    'orders',
    'created_at',
    now()->subDays(30)->toDateString(),
    now()->toDateString()
);
```

#### `validateIdsWithCustomQuery(array $ids, Closure $queryCallback, string $column = 'id'): bool`

Validación con query completamente personalizada. El callback recibe un `Query\Builder` raw y debe devolver el mismo builder (modificado).

```php
$valid = $this->validateIdsWithCustomQuery([1, 2], function ($query) {
    return $query->from('products')
                 ->where('price', '>', 100)
                 ->where('stock', '>', 0);
});
```

#### `getMissingIds(array $ids, string $table, string $column = 'id', array $additionalConditions = []): array`

Devuelve únicamente los IDs que **no** existen. Útil para construir mensajes de error descriptivos.

```php
$missing = $this->getMissingIds([1, 2, 999], 'roles', 'id', ['status' => 'active']);
// [999]

if (!empty($missing)) {
    $msg = 'IDs de rol inválidos: ' . implode(', ', $missing);
}
```

#### `clearValidationCache(string $table): bool`

Limpia el caché de validación para una tabla. Funciona de forma óptima con Redis; con otros drivers devuelve `false` (el caller debe gestionar la invalidación).

```php
$this->clearValidationCache('users');
```

### Control del caché en tiempo de ejecución

```php
// Deshabilitar para esta request
$this->disableValidationCaching();

// Reducir TTL a 5 minutos
$this->setValidationCacheTtl(300);

// Volver a habilitar
$this->enableValidationCaching();
```

---

## Reglas de validación

Todas las reglas están bajo el namespace `Ronu\RestGenericClass\Core\Rules` y reciben como primer argumento el **nombre de la conexión** de base de datos.

---

### `IdsExistInTable`

Verifica que todos los IDs del array existan en una tabla y columna dadas.

**Constructor:**
```php
new IdsExistInTable(
    string $connection,
    string $table,
    string $column = 'id'
)
```

**Uso básico:**
```php
use Ronu\RestGenericClass\Core\Rules\IdsExistInTable;

public function rules(): array
{
    return [
        'role_ids'   => ['required', 'array', new IdsExistInTable('mysql', 'roles')],
        'tag_ids'    => ['nullable', 'array', new IdsExistInTable('mysql', 'tags', 'id')],
        // Contra conexión secundaria
        'branch_ids' => ['required', 'array', new IdsExistInTable('pgsql', 'branches')],
    ];
}
```

**Mensaje de error producido:**
```
The following IDs do not exist: 5, 12, 99
```

---

### `IdsExistNotDelete`

Idéntica a `IdsExistInTable` pero excluye registros con soft delete (`deleted_at IS NOT NULL`).

**Constructor:**
```php
new IdsExistNotDelete(
    string $connection,
    string $table,
    string $column = 'id'
)
```

**Uso típico (garantizar que el recurso no fue borrado):**
```php
use Ronu\RestGenericClass\Core\Rules\IdsExistNotDelete;

'user_ids' => [
    'required',
    'array',
    new IdsExistNotDelete('mysql', 'users'),
],
```

> **Nota:** La tabla debe tener la columna `deleted_at` para que esta regla tenga efecto. Si la columna no existe, la query lanzará una excepción que se captura internamente y devuelve `false`.

---

### `IdsExistWithAnyStatus`

Valida que los IDs existan con al menos uno de los statuses indicados.

**Constructor:**
```php
new IdsExistWithAnyStatus(
    string $connection,
    string $table,
    array  $statuses,
    string $column = 'status'
)
```

**Uso:**
```php
use Ronu\RestGenericClass\Core\Rules\IdsExistWithAnyStatus;

// Aceptar clientes activos o en prueba
'client_ids' => [
    'required',
    'array',
    new IdsExistWithAnyStatus('mysql', 'clients', ['active', 'trial']),
],

// Con columna personalizada
'product_ids' => [
    'required',
    'array',
    new IdsExistWithAnyStatus('mysql', 'products', ['available', 'preorder'], 'state'),
],
```

---

### `IdsExistWithDateRange`

Valida que los IDs existan y que su campo de fecha esté dentro del rango especificado.

**Constructor:**
```php
new IdsExistWithDateRange(
    string  $connection,
    string  $table,
    string  $dateColumn,
    ?string $startDate            = null,
    ?string $endDate              = null,
    array   $additionalConditions = []
)
```

**Uso:**
```php
use Ronu\RestGenericClass\Core\Rules\IdsExistWithDateRange;

// Órdenes del mes actual
'order_ids' => [
    'required',
    'array',
    new IdsExistWithDateRange(
        connection:  'mysql',
        table:       'orders',
        dateColumn:  'created_at',
        startDate:   now()->startOfMonth()->toDateString(),
        endDate:     now()->endOfMonth()->toDateString(),
    ),
],

// Solo límite superior (antes de cierta fecha)
'invoice_ids' => [
    'required',
    'array',
    new IdsExistWithDateRange('mysql', 'invoices', 'issued_at', null, '2024-12-31'),
],

// Con condiciones adicionales
'shift_ids' => [
    'required',
    'array',
    new IdsExistWithDateRange(
        'mysql',
        'shifts',
        'date',
        now()->subDays(7)->toDateString(),
        now()->toDateString(),
        ['status' => 'completed']
    ),
],
```

> **Comportamiento:** A diferencia de otras reglas, `IdsExistWithDateRange` hace la query directamente sobre los IDs recibidos (no cachea la tabla completa) ya que el rango es dinámico.

---

### `IdsWithCustomQuery`

La regla más flexible: acepta un `Closure` que recibe un `Query\Builder` raw y debe devolver el mismo builder configurado. El conteo resultante se compara con la cantidad de IDs.

**Constructor:**
```php
new IdsWithCustomQuery(
    string  $connection,
    Closure $queryCallback,
    string  $column = 'id'
)
```

**Uso:**
```php
use Ronu\RestGenericClass\Core\Rules\IdsWithCustomQuery;
use Illuminate\Support\Facades\DB;

// Productos disponibles con stock > 0
'product_ids' => [
    'required',
    'array',
    new IdsWithCustomQuery('mysql', function ($query) {
        return $query->from('products')
                     ->where('active', 1)
                     ->where('stock', '>', 0);
    }),
],

// Membresías vigentes (fecha de expiración futura)
'membership_ids' => [
    'required',
    'array',
    new IdsWithCustomQuery('mysql', function ($query) {
        return $query->from('memberships')
                     ->where('expires_at', '>', now());
    }),
],

// Con joins complejos
'employee_ids' => [
    'required',
    'array',
    new IdsWithCustomQuery('mysql', function ($query) {
        return $query->from('employees')
                     ->join('departments', 'employees.department_id', '=', 'departments.id')
                     ->where('departments.active', true)
                     ->where('employees.status', 'active')
                     ->select('employees.id');
    }),
],
```

> **Importante:** El callback recibe un `\Illuminate\Database\Query\Builder` (no Eloquent). El `whereIn($column, $ids)` se aplica **después** de que el callback configure la query base.

---

### `ArrayCount`

Valida el número de elementos en un array. Soporta mínimo, máximo, exacto o rango, con mensajes de error completamente personalizables en tres niveles de prioridad.

**Constructor:**
```php
new ArrayCount(
    ?int    $min      = null,
    ?int    $max      = null,
    ?string $message  = null,   // Nivel 1: mensaje global
    array   $messages = [],     // Nivel 2: mensajes por escenario
)
```

Al menos uno de `$min` o `$max` es **obligatorio**. Si `$min > $max`, lanza `\InvalidArgumentException` en tiempo de construcción.

**Escenarios y tokens:**

| Escenario clave | Cuándo se activa | Tokens disponibles |
|---|---|---|
| `onNotArray` | El valor no es un array | `:attribute`, `:count` |
| `onExact` | `min === max` y el conteo difiere | `:attribute`, `:min`, `:max`, `:count` |
| `onBetween` | `min !== null && max !== null` y fuera de rango | `:attribute`, `:min`, `:max`, `:count` |
| `onMin` | Solo `min` definido y conteo menor | `:attribute`, `:min`, `:count` |
| `onMax` | Solo `max` definido y conteo mayor | `:attribute`, `:max`, `:count` |

**Prioridad de mensajes:** `messages[scenario]` > `message` > mensaje por defecto interno.

**Ejemplos:**

```php
use Ronu\RestGenericClass\Core\Rules\ArrayCount;

// Solo máximo (máx. 1 dirección)
'address_ids' => [
    'required',
    'array',
    new ArrayCount(max: 1, message: 'Solo se permite una dirección de envío.'),
],

// Solo mínimo
'tag_ids' => [
    'required',
    'array',
    new ArrayCount(min: 1),
],

// Exactamente N elementos
'player_ids' => [
    'required',
    'array',
    new ArrayCount(min: 11, max: 11, message: 'Un equipo debe tener exactamente 11 jugadores.'),
],

// Rango con mensajes por escenario
'item_ids' => [
    'required',
    'array',
    new ArrayCount(
        min: 2,
        max: 10,
        messages: [
            'onMin'     => 'Debes seleccionar al menos :min artículos.',
            'onMax'     => 'No puedes seleccionar más de :max artículos.',
            'onBetween' => 'Selecciona entre :min y :max artículos (tienes :count).',
        ]
    ),
],

// Tokens en mensaje global
'photo_ids' => [
    'nullable',
    'array',
    new ArrayCount(
        max: 5,
        message: 'El campo :attribute admite un máximo de :max fotos (enviaste :count).'
    ),
],
```

> **Por qué `ValidatorAwareRule`:** Laravel llama internamente `Str::upper()` sobre el valor crudo al construir mensajes de reemplazo — lo que provoca "Array to string conversion" en arrays. `ArrayCount` empuja los mensajes directamente a `errors()->add()`, evitando ese pipeline completamente.

---

## `BaseFormRequest`: validación avanzada

`BaseFormRequest` ya incluye el trait `ValidatesExistenceInDatabase` y expone métodos adicionales para escenarios complejos.

### `addMessageValidator(Closure $callback): Closure`

Registra una callback de validación diferida que se ejecuta **después** de que Laravel construye el `Validator`. Esto es necesario cuando necesitas acceder a `$validator->errors()` de forma segura o cuando la lógica de validación depende de campos validados previamente.

```php
public function rules(): array
{
    return [
        'users'   => ['required', 'array'],
        'users.*' => ['integer'],
        // La closure devuelta es un no-op para Laravel;
        // la lógica real va a withValidator() via pendingValidatorCallbacks
        'users'   => $this->addMessageValidator(function (Validator $validator) {
            $ids     = $this->input('users', []);
            $missing = $this->getMissingIds($ids, 'users', 'id', ['active' => true]);
            if (!empty($missing)) {
                $validator->errors()->add(
                    'users',
                    'Los siguientes usuarios no existen o están inactivos: ' . implode(', ', $missing)
                );
            }
        }),
    ];
}
```

### `withValidator(Validator $validator): void`

Hook nativo de Laravel que el paquete usa para ejecutar todas las callbacks registradas con `addMessageValidator()`. No es necesario sobreescribirlo en tu `FormRequest`; si lo haces, llama a `parent::withValidator($validator)` para no romper el mecanismo.

### `validateIdsWithRelation(array $ids, string $table, string $relationColumn, int|string $relationValue, array $additionalConditions = []): bool`

Valida que los IDs existan **y** pertenezcan a un recurso padre específico.

```php
// En el FormRequest de una sub-ruta: /departments/{department}/employees
protected function validateEmployeesBelongToDepartment(array $ids): bool
{
    return $this->validateIdsWithRelation(
        $ids,
        'employees',
        'department_id',
        $this->route('department')
    );
}
```

### `validateUnique(string $value, string $table, string $column, ?int|string $excludeId = null, array $additionalConditions = []): bool`

Validación de unicidad excluyendo el registro actual (útil en `update`).

```php
// En reglas de actualización
public function rules(): array
{
    $userId = $this->route('user');

    return [
        'email' => [
            'required',
            'email',
            function ($attribute, $value, $fail) use ($userId) {
                if (!$this->validateUnique($value, 'users', 'email', $userId)) {
                    $fail('El correo ya está registrado por otro usuario.');
                }
            },
        ],
    ];
}
```

### `getMissingIdsMessage(array $ids, string $table, string $resourceName, array $conditions = []): ?string`

Devuelve un string de error formateado o `null` si todos los IDs existen.

```php
$error = $this->getMissingIdsMessage([1, 2, 999], 'roles', 'roles', ['active' => 1]);
// "The following roles IDs do not exist or are invalid: 999"
```

### `validatedWith(array $additionalData): array`

Combina los datos validados con datos adicionales calculados (p. ej., el ID del usuario autenticado).

```php
public function store(MyFormRequest $request): JsonResponse
{
    $data = $request->validatedWith([
        'created_by' => auth()->id(),
        'tenant_id'  => request()->header('X-Tenant-Id'),
    ]);

    $this->service->create($data);
    // ...
}
```

### `isCreating()` / `isUpdating()`

Helpers semánticos para lógica condicional en las reglas.

```php
public function rules(): array
{
    return [
        'password' => $this->isCreating() ? ['required', 'min:8'] : ['nullable', 'min:8'],
        'email'    => [
            'required',
            'email',
            $this->isUpdating()
                ? Rule::unique('users')->ignore($this->route('user'))
                : Rule::unique('users'),
        ],
    ];
}
```

---

## Patrones de uso combinado

### Patrón 1: FormRequest con múltiples reglas de IDs

```php
<?php

namespace App\Http\Requests;

use Ronu\RestGenericClass\Core\Requests\BaseFormRequest;
use Ronu\RestGenericClass\Core\Rules\IdsExistInTable;
use Ronu\RestGenericClass\Core\Rules\IdsExistNotDelete;
use Ronu\RestGenericClass\Core\Rules\IdsExistWithAnyStatus;
use Ronu\RestGenericClass\Core\Rules\ArrayCount;

class OrderStoreRequest extends BaseFormRequest
{
    protected string $entity_name = 'order';

    public function rules(): array
    {
        return [
            'product_ids' => [
                'required',
                'array',
                new ArrayCount(min: 1, max: 50, messages: [
                    'onMin' => 'El pedido debe incluir al menos un producto.',
                    'onMax' => 'No se pueden incluir más de :max productos por pedido.',
                ]),
                new IdsExistWithAnyStatus('mysql', 'products', ['active', 'featured']),
            ],
            'coupon_ids' => [
                'nullable',
                'array',
                new ArrayCount(max: 3, message: 'Máximo 3 cupones por pedido.'),
                new IdsExistNotDelete('mysql', 'coupons'),
            ],
            'address_id' => [
                'required',
                'integer',
                // Validar que la dirección pertenece al usuario autenticado
                function ($attribute, $value, $fail) {
                    if (!$this->validateIdsExistInTable([$value], 'addresses', 'id', [
                        'user_id' => auth()->id(),
                    ])['success']) {
                        $fail('La dirección no pertenece a tu cuenta.');
                    }
                },
            ],
        ];
    }
}
```

### Patrón 2: Validación diferida con `addMessageValidator`

```php
<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;
use Ronu\RestGenericClass\Core\Requests\BaseFormRequest;

class BulkAssignRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'user_ids'       => ['required', 'array'],
            'user_ids.*'     => ['integer', 'min:1'],
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'min:1'],

            // Validación diferida: necesita el Validator construido
            '_deferred_users' => $this->addMessageValidator(
                function (Validator $v) {
                    $ids     = $this->input('user_ids', []);
                    $missing = $this->getMissingIds($ids, 'users', 'id', ['active' => true]);
                    if (!empty($missing)) {
                        $v->errors()->add(
                            'user_ids',
                            'Usuarios inactivos o inexistentes: ' . implode(', ', $missing)
                        );
                    }
                }
            ),
        ];
    }
}
```

### Patrón 3: Rango de fechas dinámico desde el request

```php
<?php

namespace App\Http\Requests;

use Ronu\RestGenericClass\Core\Requests\BaseFormRequest;
use Ronu\RestGenericClass\Core\Rules\IdsExistWithDateRange;

class ReportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $startDate = $this->input('start_date');
        $endDate   = $this->input('end_date');

        return [
            'start_date'  => ['required', 'date'],
            'end_date'    => ['required', 'date', 'after_or_equal:start_date'],
            'invoice_ids' => [
                'required',
                'array',
                new IdsExistWithDateRange(
                    'mysql',
                    'invoices',
                    'issued_at',
                    $startDate,
                    $endDate,
                    ['status' => 'paid']
                ),
            ],
        ];
    }
}
```

### Patrón 4: Query personalizada con lógica de negocio compleja

```php
use Ronu\RestGenericClass\Core\Rules\IdsWithCustomQuery;

'slot_ids' => [
    'required',
    'array',
    new ArrayCount(min: 1, max: 5),
    new IdsWithCustomQuery('mysql', function ($query) {
        // Slots disponibles: no ocupados Y en el futuro
        return $query->from('appointment_slots')
                     ->where('available', true)
                     ->where('starts_at', '>', now())
                     ->whereNotExists(function ($sub) {
                         $sub->from('appointments')
                             ->whereColumn('appointments.slot_id', 'appointment_slots.id')
                             ->where('appointments.status', '!=', 'cancelled');
                     });
    }),
],
```

---

## Manejo de errores y logging

Todas las reglas de IDs capturan excepciones de base de datos internamente y las registran via `Log::error()` con el canal `rest-generic-class`. En caso de error, la validación devuelve `false` (fail-safe). Los mensajes de error estándar son:

```
The following IDs do not exist: 5, 12
```

Para mensajes más descriptivos, usa `getMissingIds()` o `getMissingIdsMessage()` directamente en el `FormRequest`.

---

## Consideraciones de rendimiento

| Regla | Estrategia de caché | Cuándo NO usa caché |
|---|---|---|
| `IdsExistInTable` | Caché de la tabla completa (pluck) | Cuando `enableValidationCache = false` |
| `IdsExistNotDelete` | Caché de IDs no eliminados | Ídem |
| `IdsExistWithAnyStatus` | Caché por combinación de statuses | Ídem |
| `IdsExistWithDateRange` | **Sin caché** (rango dinámico) | Siempre query directa |
| `IdsWithCustomQuery` | **Sin caché** (lógica arbitraria) | Siempre query directa |
| `ArrayCount` | N/A (sin BD) | N/A |

> Para `IdsExistWithDateRange` e `IdsWithCustomQuery`, si el rendimiento es crítico, considera pre-validar los IDs con `IdsExistInTable` primero y usar la regla con fecha/custom solo para la condición adicional.

---

## Evidencia

- `src/Core/Rules/IdsExistInTable.php` — Regla de existencia básica
- `src/Core/Rules/IdsExistNotDelete.php` — Regla con soft deletes
- `src/Core/Rules/IdsExistWithAnyStatus.php` — Regla con múltiples statuses
- `src/Core/Rules/IdsExistWithDateRange.php` — Regla con rango de fechas
- `src/Core/Rules/IdsWithCustomQuery.php` — Regla con query personalizada
- `src/Core/Rules/ArrayCount.php` — Regla de conteo de arrays
- `src/Core/Traits/ValidatesExistenceInDatabase.php` — Trait base con toda la lógica
- `src/Core/Requests/BaseFormRequest.php` — FormRequest con integración del trait y helpers adicionales

[Volver al índice de documentación](../index.md)
