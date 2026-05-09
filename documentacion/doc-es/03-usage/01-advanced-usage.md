# Uso avanzado

## Filtros en relaciones anidadas

Usa claves `oper` anidadas para filtrar por modelos relacionados. El paquete valida los nombres de relaciones contra `const RELATIONS`.

```json
{
  "oper": {
    "and": ["status|=|active"],
    "category": {
      "and": ["name|like|%electronics%"]
    }
  }
}
```

## Filtrar relaciones cuando `_nested=true`

Cuando `_nested` es true, los filtros de relaciones se aplican tanto a las relaciones cargadas como a la consulta raíz.

```json
{
  "_nested": true,
  "relations": ["category:id,name"],
  "oper": {
    "category": {
      "and": ["name|like|%electronics%"]
    }
  }
}
```

## Ordenamiento por campos de relaciones

El parámetro `orderby` acepta notación de punto para ordenar por columnas de entidades relacionadas. Cada segmento previo al último es un método de relación que **debe estar declarado en `const RELATIONS`** del modelo. El último segmento es la columna por la que se ordena en la relación hoja.

```json
{
  "orderby": [
    {"user.name": "asc"},
    {"category.parent.name": "desc"},
    {"created_at": "desc"}
  ]
}
```

### Cómo se traduce

Cada entrada con punto se convierte en un **subquery escalar de ordenamiento** en el SQL — no se agregan JOINs a la consulta principal. Por ejemplo, para ordenar clientes por el nombre del usuario asociado:

```sql
SELECT clients.*
FROM clients
ORDER BY (
  SELECT users.name
  FROM users
  WHERE users.id = clients.user_id
  LIMIT 1
) ASC
```

Para rutas anidadas (`user.role.name`) los subqueries se anidan:

```sql
ORDER BY (
  SELECT (
    SELECT roles.name
    FROM roles
    WHERE roles.id = users.role_id
    LIMIT 1
  ) AS value
  FROM users
  WHERE users.id = clients.user_id
  LIMIT 1
) ASC
```

### Por qué subquery (y no JOIN)

- Funciona uniformemente para `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, `MorphTo`, `MorphOne`, `MorphMany` y `HasManyThrough` — `getRelationExistenceQuery` de Eloquent construye los `WHERE` correctos (claves foráneas, claves de propietario, filtro de tipo polimórfico, vínculo con la tabla pivote).
- Sin filas duplicadas en relaciones `*-to-many`, así que no hace falta `DISTINCT`/`GROUP BY`.
- No colisiona con filtros `whereHas` que apunten a la misma relación.
- No requiere alias para relaciones auto-referenciales.
- Soft deletes y scopes globales del modelo relacionado se aplican automáticamente.

### Combinar filtro y orden sobre la misma relación

`oper` (whereHas) y `orderby` (subquery) son independientes y se componen sin conflicto:

```json
{
  "oper": {
    "user": { "and": ["name|like|%a%"] }
  },
  "orderby": [
    {"user.name": "desc"}
  ]
}
```

### Las columnas locales se prefijan automáticamente

Cuando una entrada `orderby` no tiene punto, la columna se prefija automáticamente con el nombre de la tabla del modelo. Esto evita ambigüedad cuando la consulta lleva un JOIN o un subquery `whereHas` que referencia una columna con el mismo nombre en una tabla relacionada.

### Validación y límites

- Cada segmento de relación se valida contra `const RELATIONS` del modelo correspondiente. Segmentos desconocidos o no permitidos producen HTTP 400.
- La profundidad de la ruta está acotada por `rest-generic-class.filtering.max_depth` (por defecto `5`), compartida con el motor de `oper`.
- La dirección se normaliza: `desc` solo cuando se solicita explícitamente; cualquier otro valor cae a `asc`.

### Retrocompatibilidad

Si el primer segmento de una entrada con punto **no** es un método del modelo, el valor se pasa al query builder tal cual. Esto preserva el uso existente donde los consumidores proveen un literal `"tabla.columna"` después de un JOIN manual.

## Paginación por cursor

```json
{
  "pagination": {
    "infinity": true,
    "pageSize": 50,
    "cursor": "eyJpZCI6MTAwfQ=="
  }
}
```

## Listado jerárquico

Habilita jerarquía definiendo `const HIERARCHY_FIELD_ID` en tu modelo (por ejemplo, `parent_id`).

```json
{
  "hierarchy": {
    "filter_mode": "with_descendants",
    "max_depth": 3,
    "children_key": "children",
    "include_empty_children": true
  }
}
```

El mismo parámetro `hierarchy` puede usarse en `show()` para devolver una rama de un registro.

## Helpers de exportación (opcional)

`exportExcel()` y `exportPdf()` dependen de paquetes opcionales. Instálalos antes de usar:

```bash
composer require maatwebsite/excel barryvdh/laravel-dompdf
```

### Parámetros de exportación (guía junior)

Ambos helpers usan el **mismo pipeline de filtrado** que `list_all()`, así que tus filtros, relaciones y reglas de paginación siguen aplicando. Puedes controlar qué columnas se exportan y, para PDFs, qué plantilla Blade se utiliza.

#### Parámetros comunes

| Parámetro | Tipo | Ejemplo | Qué hace |
| --- | --- | --- | --- |
| `select` | `array` o `string` | `["id","name"]` o `"id,name"` | Controla qué columnas se consultan. |
| `columns` | `array` o `string` | `["name","email"]` o `"name,email"` | Controla qué columnas se exportan. Si se omite, usa `select` o `fillable` cuando `select="*"`. |
| `pagination` | `object` | `{ "page": 1, "pageSize": 50 }` | Mantiene la paginación existente. Exporta solo la página solicitada salvo que uses paginación infinita. |
| `filename` | `string` | `"users-2024-10-01.xlsx"` | Sobrescribe el nombre por defecto del archivo exportado. |

#### Parámetros solo para PDF

| Parámetro | Tipo | Ejemplo | Qué hace |
| --- | --- | --- | --- |
| `template` | `string` | `"pdf"` o `"reports.users"` | Nombre de la vista Blade para renderizar el PDF. Default: `pdf`. |

#### Ejemplo: exportar Excel (filtrado + columnas específicas)

```json
{
  "select": ["id", "name", "email"],
  "columns": ["name", "email"],
  "oper": { "and": ["active|=|1"] },
  "pagination": { "page": 1, "pageSize": 25 },
  "filename": "active-users.xlsx"
}
```

#### Ejemplo: exportar PDF (filtrado + plantilla Blade)

```json
{
  "select": "*",
  "columns": ["name", "email", "created_at"],
  "oper": { "and": ["active|=|1"] },
  "template": "pdf",
  "filename": "active-users.pdf"
}
```

[Volver al índice de documentación](../index.md)

## Evidencia
- Archivo: src/Core/Services/BaseService.php
  - Símbolo: BaseService::applyOperTree(), BaseService::relations(), BaseService::list_all(), BaseService::show(), BaseService::listHierarchy(), BaseService::showHierarchy(), BaseService::paginateHierarchyRoots(), BaseService::exportExcel(), BaseService::exportPdf(), BaseService::order_by()
  - Notas: Demuestra filtrado anidado, carga de relaciones, jerarquía, paginación por cursor, helpers de exportación y ordenamiento con relaciones.
- Archivo: src/Core/Traits/HasDynamicOrderBy.php
  - Símbolo: HasDynamicOrderBy::applyDynamicOrderBy(), HasDynamicOrderBy::buildOrderingSubquery()
  - Notas: Implementa el parser de notación de punto y el generador de subqueries escalares de ordenamiento usado por `order_by` y `applyOrdering`.
- Archivo: src/Core/Models/BaseModel.php
  - Símbolo: BaseModel::HIERARCHY_FIELD_ID, BaseModel::hasHierarchyField(), BaseModel::RELATIONS
  - Notas: Muestra el contrato del modelo requerido para jerarquía y la lista blanca de relaciones consultada por filtrado y ordenamiento.

## Validación de arrays de IDs con reglas personalizadas

El paquete incluye seis reglas de validación listas para usar en cualquier `FormRequest`. Todas operan sobre arrays de IDs con caché integrado.

### Uso rápido en `rules()`

```php
use Ronu\RestGenericClass\Core\Rules\IdsExistInTable;
use Ronu\RestGenericClass\Core\Rules\IdsExistNotDelete;
use Ronu\RestGenericClass\Core\Rules\IdsExistWithAnyStatus;
use Ronu\RestGenericClass\Core\Rules\IdsExistWithDateRange;
use Ronu\RestGenericClass\Core\Rules\IdsWithCustomQuery;
use Ronu\RestGenericClass\Core\Rules\ArrayCount;

public function rules(): array
{
    return [
        // Existencia simple
        'role_ids'   => ['required', 'array', new IdsExistInTable('mysql', 'roles')],

        // Excluye soft-deleted
        'user_ids'   => ['required', 'array', new IdsExistNotDelete('mysql', 'users')],

        // Con uno de varios statuses
        'client_ids' => ['required', 'array',
            new IdsExistWithAnyStatus('mysql', 'clients', ['active', 'trial'])
        ],

        // Dentro de un rango de fechas
        'order_ids'  => ['required', 'array',
            new IdsExistWithDateRange(
                'mysql', 'orders', 'created_at',
                now()->subDays(30)->toDateString(),
                now()->toDateString()
            )
        ],

        // Query completamente personalizada
        'slot_ids'   => ['required', 'array',
            new IdsWithCustomQuery('mysql', fn($q) =>
                $q->from('slots')->where('available', true)->where('starts_at', '>', now())
            )
        ],

        // Conteo de array
        'photo_ids'  => ['required', 'array',
            new ArrayCount(min: 1, max: 5, messages: [
                'onMin' => 'Sube al menos :min foto.',
                'onMax' => 'Máximo :max fotos permitidas.',
            ])
        ],
    ];
}
```

### Validación diferida con `addMessageValidator`

Cuando la lógica depende del Validator ya construido (p. ej., cruzar dos campos validados), usa el hook diferido de `BaseFormRequest`:

```php
use Illuminate\Validation\Validator;

public function rules(): array
{
    return [
        'product_ids'   => ['required', 'array'],
        'product_ids.*' => ['integer'],
        '_check_products' => $this->addMessageValidator(function (Validator $v) {
            $ids     = $this->input('product_ids', []);
            $missing = $this->getMissingIds($ids, 'products', 'id', ['active' => true]);
            if (!empty($missing)) {
                $v->errors()->add('product_ids',
                    'Productos inactivos o inexistentes: ' . implode(', ', $missing));
            }
        }),
    ];
}
```

Referencia completa de reglas y trait → [04-reference/05-validation-rules.md](../04-reference/05-validation-rules.md)
