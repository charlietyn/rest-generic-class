# Relaciones muchos a muchos

Esta sección cubre el trait `ManagesManyToMany`, que proporciona métodos genéricos de lectura y mutación para controladores que necesitan exponer relaciones muchos a muchos con filtrado completo, paginación, ordenamiento y operaciones CRUD/pivot configurables.

## Descripción general

El trait está diseñado para usarse en cualquier controlador que gestione una relación `BelongsToMany`. Soporta:

- **Listar** entidades relacionadas con filtros, ordenamiento, paginación y carga anticipada (eager-loading).
- **Mostrar** una entidad relacionada individual.
- **Crear** entidades relacionadas (individual o masivo).
- **Actualizar** entidades relacionadas (individual o masivo).
- **Eliminar** entidades relacionadas (con eliminación opcional del modelo).
- **Vincular/desvincular** entidades existentes (operaciones solo de pivot).
- **Sincronizar** el conjunto completo de la relación con datos de pivot.
- **Alternar** IDs específicos en la relación con datos de pivot.
- **Actualizar pivot** campos de la tabla pivot sin modificar el modelo relacionado.

### Registrar el trait

Agrega `use ManagesManyToMany;` a tu controlador y define la propiedad `$manyToManyConfig`:

```php
use Ronu\RestGenericClass\Core\Traits\ManagesManyToMany;

class UserController extends Controller
{
    use ManagesManyToMany;

    protected array $manyToManyConfig = [
        'addresses' => [
            'relationship'  => 'array_address',
            'relatedModel'  => Addresses::class,
            'pivotModel'    => UserAddresses::class,
            'parentModel'   => Users::class,
            'parentKey'     => 'user_id',
            'relatedKey'    => 'address_id',

            'mutation' => [
                'dataKey'       => ['Addresses', 'addresses'],
                'deleteRelated' => true,
                'pivotColumns'  => ['is_primary', 'label', 'expires_at'],
            ],
        ],
    ];
}
```

## Referencia de configuración

| Clave | Tipo | Requerido | Por defecto | Descripción |
| --- | --- | --- | --- | --- |
| `relationship` | `string` | Sí | — | Nombre del método `BelongsToMany` en el modelo padre |
| `relatedModel` | `string` | Sí | — | Nombre completo de la clase del modelo Eloquent relacionado |
| `pivotModel` | `string` | Sí | — | Nombre completo de la clase del modelo Eloquent de la tabla pivot |
| `parentModel` | `string` | Sí | — | Nombre completo de la clase del modelo Eloquent padre |
| `parentKey` | `string` | Sí | — | Columna de clave foránea que referencia al padre en la tabla pivot |
| `relatedKey` | `string` | Sí | — | Columna de clave foránea que referencia al modelo relacionado en la tabla pivot |
| `mutation.dataKey` | `string\|array` | No | `[]` | Clave(s) para extraer datos masivos del cuerpo de la solicitud |
| `mutation.deleteRelated` | `bool` | No | `true` | Si se debe eliminar el modelo relacionado al llamar a `deleteRelation` |
| `mutation.pivotColumns` | `array` | No | `[]` | Lista blanca de nombres de columnas pivot permitidas; cuando está vacía se aceptan todas |

## Escenarios

El parámetro de ruta `_scenario` determina el modo de operación. El middleware `inject` debe establecer `_relation` y `_scenario` en la solicitud.

| Escenario | Método | Verbo HTTP | Descripción |
| --- | --- | --- | --- |
| `attach` | `attachRelation` | POST | Vincular una entidad relacionada con datos de pivot opcionales |
| `bulk_attach` | `attachRelation` | POST | Vincular múltiples entidades relacionadas con datos de pivot opcionales |
| `sync` | `attachRelation` | POST | Reemplazar el conjunto completo de la relación (desvincula los ausentes, vincula los nuevos, actualiza los existentes) |
| `toggle` | `attachRelation` | POST | Alternar IDs específicos: los vinculados se desvinculan y viceversa |
| `detach` | `detachRelation` | DELETE | Eliminar una fila pivot individual (no elimina el modelo relacionado) |
| `bulk_detach` | `detachRelation` | DELETE | Eliminar múltiples filas pivot |
| `update_pivot` | `updatePivotRelation` | PUT | Actualizar campos pivot para una entidad relacionada |
| `bulk_update_pivot` | `updatePivotRelation` | PUT | Actualizar campos pivot para múltiples entidades relacionadas |

## Convención de datos pivot

Para los escenarios que aceptan datos de pivot (`attach`, `bulk_attach`, `sync`, `toggle`), la convención es:

> Todo lo que esté en el cuerpo de la solicitud excepto los campos `relatedKey` e `id` se trata como datos de pivot.

Por ejemplo, con `relatedKey = "address_id"`, un cuerpo de `{"address_id": 5, "is_primary": true, "label": "Home"}` resulta en vincular la dirección `5` con los valores pivot `{"is_primary": true, "label": "Home"}`.

## Ejemplos JSON para cada escenario

### `attach` — vinculación individual con pivot

```http
POST /api/v1/users/1/addresses?_relation=addresses&_scenario=attach
Content-Type: application/json

{
  "address_id": 5,
  "is_primary": true,
  "label": "Home"
}
```

Respuesta:

```json
{
  "attached": [5]
}
```

### `bulk_attach` — vinculación múltiple con pivot

Requiere que `dataKey` esté configurado para poder extraer el array de elementos del cuerpo.

```http
POST /api/v1/users/1/addresses?_relation=addresses&_scenario=bulk_attach
Content-Type: application/json

{
  "addresses": [
    { "address_id": 5, "is_primary": true, "label": "Home" },
    { "address_id": 8, "is_primary": false, "label": "Work" }
  ]
}
```

Respuesta:

```json
{
  "attached": [5, 8]
}
```

### `sync` — reemplazar conjunto de relación

Sync acepta tres formatos de entrada:

**Formato 1** — lista plana de IDs (sin datos de pivot):

```http
POST /api/v1/users/1/addresses?_relation=addresses&_scenario=sync
Content-Type: application/json

[1, 2, 3]
```

**Formato 2** — lista de objetos con `relatedKey` y columnas pivot:

```http
POST /api/v1/users/1/addresses?_relation=addresses&_scenario=sync
Content-Type: application/json

[
  { "address_id": 1, "is_primary": true },
  { "address_id": 2, "is_primary": false, "label": "Work" },
  { "address_id": 3 }
]
```

**Formato 3** — mapa asociativo nativo de Laravel:

```http
POST /api/v1/users/1/addresses?_relation=addresses&_scenario=sync
Content-Type: application/json

{
  "1": { "is_primary": true },
  "2": { "is_primary": false, "label": "Work" },
  "3": {}
}
```

Respuesta (todos los formatos):

```json
{
  "attached": [2, 3],
  "detached": [7, 9],
  "updated": [1]
}
```

### `toggle` — alternar IDs específicos

Toggle acepta los mismos tres formatos de entrada que `sync`.

**Formato 1** — lista plana de IDs:

```json
[1, 2, 3]
```

**Formato 2** — objetos con columnas pivot:

```json
[
  { "address_id": 1, "is_primary": true },
  { "address_id": 2, "label": "Work" }
]
```

**Formato 3** — mapa nativo de Laravel:

```json
{
  "1": { "is_primary": true },
  "2": {}
}
```

Respuesta:

```json
{
  "attached": [2, 3],
  "detached": [1]
}
```

### `detach` — desvinculación individual

```http
DELETE /api/v1/users/1/addresses/5?_relation=addresses&_scenario=detach
```

Respuesta:

```json
{
  "detached": 1
}
```

### `bulk_detach` — desvinculación múltiple

```http
DELETE /api/v1/users/1/addresses?_relation=addresses&_scenario=bulk_detach
Content-Type: application/json

[5, 8, 12]
```

Respuesta:

```json
{
  "detached": 3
}
```

### `update_pivot` — actualizar campos pivot (individual)

```http
PUT /api/v1/users/1/addresses/5?_relation=addresses&_scenario=update_pivot
Content-Type: application/json

{
  "is_primary": true,
  "label": "Main office"
}
```

### `bulk_update_pivot` — actualizar campos pivot (masivo)

Requiere que `dataKey` esté configurado.

```http
PUT /api/v1/users/1/addresses?_relation=addresses&_scenario=bulk_update_pivot
Content-Type: application/json

{
  "addresses": [
    { "address_id": 5, "is_primary": true, "label": "Main office" },
    { "address_id": 8, "is_primary": false, "label": "Warehouse" }
  ]
}
```

## Lista blanca `pivotColumns`

La clave opcional `pivotColumns` en la configuración de mutación proporciona una lista blanca de seguridad que restringe qué atributos pivot se aceptan. Cuando está configurada, cualquier columna pivot que **no** esté en la lista blanca se elimina silenciosamente de los datos antes de llegar a la base de datos — la solicitud no es rechazada.

### Por qué es importante

Sin una lista blanca, cualquier campo enviado en el cuerpo de la solicitud (aparte del `relatedKey` e `id`) se escribe directamente en la tabla pivot. Un cliente malintencionado o descuidado podría sobrescribir columnas como `created_by`, `approved_at` u otros campos sensibles. La lista blanca previene esto.

### Configuración

```php
'mutation' => [
    'dataKey'       => ['Addresses', 'addresses'],
    'deleteRelated' => true,
    'pivotColumns'  => ['is_primary', 'label', 'expires_at'],
],
```

### Comportamiento

Con la configuración anterior, si un cliente envía:

```json
{
  "address_id": 5,
  "is_primary": true,
  "label": "Home",
  "approved_at": "2025-01-01"
}
```

El campo `approved_at` se elimina silenciosamente. Solo `is_primary` y `label` se almacenan en la tabla pivot.

Cuando `pivotColumns` no está definido o es un array vacío, se aceptan todas las columnas pivot (comportamiento retrocompatible).

La lista blanca se aplica en los cuatro escenarios de vinculación: `attach`, `bulk_attach`, `sync` y `toggle`.

## Requisitos de configuración del modelo

Para que las columnas pivot aparezcan en las respuestas de consulta, la relación `BelongsToMany` en el modelo padre debe declararlas con `->withPivot()`:

```php
// En el modelo Users
public function array_address(): BelongsToMany
{
    return $this->belongsToMany(Addresses::class, 'user_addresses', 'user_id', 'address_id')
                ->withPivot(['is_primary', 'label', 'expires_at'])
                ->withTimestamps();
}
```

Sin `->withPivot()`, las columnas pivot se almacenarán correctamente pero **no** se incluirán en la respuesta al listar o mostrar entidades relacionadas.

## Configuración de rutas

El middleware `inject` debe inyectar `_relation` y `_scenario` en la solicitud. Un ejemplo mínimo de registro de rutas:

```php
use Illuminate\Support\Facades\Route;

Route::prefix('users/{parent_id}/addresses')->group(function () {
    // Lectura
    Route::get('/', [UserController::class, 'listRelation'])
        ->middleware('inject:_relation,addresses');

    Route::get('/{relatedId}', [UserController::class, 'showRelation'])
        ->middleware('inject:_relation,addresses');

    // Vincular / sincronizar / alternar
    Route::post('/', [UserController::class, 'attachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,attach');

    Route::post('/bulk', [UserController::class, 'attachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,bulk_attach');

    Route::post('/sync', [UserController::class, 'attachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,sync');

    Route::post('/toggle', [UserController::class, 'attachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,toggle');

    // Desvincular
    Route::delete('/{relatedId}', [UserController::class, 'detachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,detach');

    Route::delete('/bulk', [UserController::class, 'detachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,bulk_detach');

    // Actualizar pivot
    Route::put('/{relatedId}/pivot', [UserController::class, 'updatePivotRelation'])
        ->middleware('inject:_relation,addresses,_scenario,update_pivot');

    Route::put('/pivot/bulk', [UserController::class, 'updatePivotRelation'])
        ->middleware('inject:_relation,addresses,_scenario,bulk_update_pivot');
});
```

[Volver al índice de documentación](../index.md)

## Evidencia

- Archivo: `src/Core/Traits/ManagesManyToMany.php`
  - Símbolo: `ManagesManyToMany::attachRelation()`, `ManagesManyToMany::detachRelation()`, `ManagesManyToMany::updatePivotRelation()`
  - Notas: Métodos de entrada para todos los escenarios de vincular/desvincular/actualizar pivot. `attachRelation()` lee `pivotColumns` de la configuración y delega a métodos privados específicos de cada escenario.
- Archivo: `src/Core/Traits/ManagesManyToMany.php`
  - Símbolo: `ManagesManyToMany::buildPivotMap()`, `ManagesManyToMany::processSyncAttach()`, `ManagesManyToMany::processToggleAttach()`
  - Notas: `buildPivotMap()` normaliza los tres formatos de entrada en un mapa compatible con Laravel `[id => [columnas_pivot]]`. `processSyncAttach()` y `processToggleAttach()` delegan en él antes de llamar a `sync()` / `toggle()`.
- Archivo: `src/Core/Traits/ManagesManyToMany.php`
  - Símbolo: `ManagesManyToMany::processSingleAttach()`, `ManagesManyToMany::processBulkAttach()`
  - Notas: Helpers de vinculación individual y masiva. Ambos aceptan un parámetro opcional `$allowedPivotCols` y filtran los datos pivot a través de la lista blanca cuando está configurada.
- Archivo: `src/Core/Traits/ManagesManyToMany.php`
  - Símbolo: `ManagesManyToMany::listRelation()`, `ManagesManyToMany::showRelation()`
  - Notas: Métodos de lectura que soportan filtros, paginación, ordenamiento y carga anticipada en relaciones muchos a muchos.
