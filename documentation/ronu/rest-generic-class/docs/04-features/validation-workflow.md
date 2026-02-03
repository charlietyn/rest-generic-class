# Validación por escenarios (BaseFormRequest + TransformData)

## Overview
`BaseFormRequest` añade soporte de escenarios (`create`, `update`, `bulk_create`, etc.) y `TransformData` ejecuta validaciones antes de llegar al controller para métodos `POST/PUT/PATCH`. 

## When to use / When NOT to use
**Úsalo cuando:**
- Necesitas reglas de validación distintas según el método o escenario.
- Quieres validar automáticamente operaciones de CRUD.

**No lo uses cuando:**
- Tu controlador requiere validaciones completamente ad-hoc sin escenarios.

## How it works
- `TransformData` toma el `FormRequest` configurado en la ruta y llama `validate_request()`.
- `BaseFormRequest` determina el escenario por `_scenario` o por HTTP method.
- `BaseFormRequest::parseRules()` permite cargar reglas desde un archivo externo.

## Configuration
No hay claves específicas en config; el flujo depende de la implementación de tu `FormRequest` y de cómo registres el middleware.

## Usage examples
```php
// routes/api.php
Route::post('products', [ProductController::class, 'store'])
    ->middleware('transformdata:' . \App\Http\Requests\ProductRequest::class);
```

```php
class ProductRequest extends BaseFormRequest
{
    protected string $entity_name = 'product';

    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'price' => 'required|numeric',
        ];
    }
}
```

## Edge cases / pitfalls
- Si `parseRules()` no encuentra el escenario, lanza excepción.
- El escenario `BULK_UPDATE` se activa cuando la ruta termina en `/update_multiple`.

## Evidence
- File: src/Core/Middleware/TransformData.php
  - Symbol: TransformData::handle()
  - Notes: ejecución de validación en `POST/PUT/PATCH`.
- File: src/Core/Requests/BaseFormRequest.php
  - Symbol: BaseFormRequest::{conditionalScenario,validate_request,parseRules}
  - Notes: reglas y escenarios de validación.
