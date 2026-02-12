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
  - Símbolo: BaseService::applyOperTree(), BaseService::relations(), BaseService::list_all(), BaseService::show(), BaseService::listHierarchy(), BaseService::showHierarchy(), BaseService::paginateHierarchyRoots(), BaseService::exportExcel(), BaseService::exportPdf()
  - Notas: Demuestra filtrado anidado, carga de relaciones, jerarquía, paginación por cursor y helpers de exportación.
- Archivo: src/Core/Models/BaseModel.php
  - Símbolo: BaseModel::HIERARCHY_FIELD_ID, BaseModel::hasHierarchyField()
  - Notas: Muestra el contrato del modelo requerido para habilitar funciones de jerarquía.

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
