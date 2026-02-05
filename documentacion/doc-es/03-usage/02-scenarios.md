# Escenarios

## Escenario 1: Búsqueda de catálogo con relaciones

**Objetivo**
Devolver un listado filtrado de productos con datos de categoría y payload mínimo.

**Configuración**
- `Product` extiende `BaseModel`.
- `ProductService` extiende `BaseService`.
- `ProductController` extiende `RestController`.
- `Product::RELATIONS` incluye `category`.

**Pasos**
1. Definir relaciones en la allowlist del modelo.
2. Llamar al endpoint de listado con `select`, `relations` y `oper`.

**Código de ejemplo**
```http
GET /api/v1/products?select=["id","name","price"]&relations=["category:id,name"]
```

```json
{
  "oper": {
    "and": ["status|=|active", "price|>=|50"]
  }
}
```

**Notas**
- Usa `relations` para evitar consultas N+1.
- Mantén `select` ajustado para reducir el tamaño del payload.

**Errores comunes**
- Olvidar agregar `category` a `RELATIONS`, lo que provoca un error 400.
- Pasar un operador inválido que no está en la allowlist.

---

## Escenario 2: Actualizaciones masivas en un flujo admin

**Objetivo**
Actualizar múltiples registros en una sola solicitud desde una interfaz admin.

**Configuración**
- Define `const MODEL` en el modelo (por ejemplo, `product`).
- Expone la ruta `updateMultiple` en el controlador.

**Pasos**
1. Incluir la clave del modelo (minúscula) en el body JSON.
2. Proveer valores de clave primaria en cada fila.

**Código de ejemplo**
```http
POST /api/v1/products/update-multiple
Content-Type: application/json

{
  "product": [
    {"id": 10, "stock": 50},
    {"id": 11, "stock": 0}
  ]
}
```

**Notas**
- El controlador envuelve las actualizaciones masivas en una transacción.

**Errores comunes**
- Omitir la clave primaria de una fila.
- Olvidar definir `MODEL` en la clase del modelo.

---

## Escenario 3: Navegación por árbol de categorías

**Objetivo**
Devolver un árbol de categorías con hijos anidados bajo cada padre.

**Configuración**
- Define `const HIERARCHY_FIELD_ID = 'parent_id'` en el modelo.

**Pasos**
1. Habilitar jerarquía en la solicitud.
2. Usar `filter_mode` para incluir descendientes.

**Código de ejemplo**
```json
{
  "hierarchy": {
    "filter_mode": "with_descendants",
    "children_key": "children",
    "max_depth": 4
  }
}
```

**Notas**
- Combina `oper` con `filter_mode` para enfocarte en una parte del árbol.

**Errores comunes**
- Habilitar `hierarchy` sin definir `HIERARCHY_FIELD_ID`.

---

## Escenario 4: Asignar permisos a roles (integración Spatie)

**Objetivo**
Sincronizar una lista de permisos a roles usando el trait integrado.

**Configuración**
- Usa `HasPermissionsController` en tu controlador.
- Asegúrate de tener instalado **spatie/laravel-permission**.

**Pasos**
1. Enviar una solicitud a la acción `assign_roles`.
2. Elegir `mode` como `ADD`, `SYNC` o `REVOKE`.

**Código de ejemplo**
```http
POST /api/permissions/assign_roles
Content-Type: application/json

{
  "roles": ["admin", "editor"],
  "guard": "api",
  "mode": "SYNC",
  "perms": ["products.view", "products.create"]
}
```

**Notas**
- El servicio también puede resolver permisos por módulo o entidad.

**Errores comunes**
- Llamar al endpoint sin instalar Spatie permissions.

[Volver al índice de documentación](../index.md)

## Evidencia
- Archivo: src/Core/Services/BaseService.php
  - Símbolo: BaseService::process_query(), BaseService::update_multiple(), BaseService::listHierarchy()
  - Notas: Soporta filtrado, actualizaciones masivas y modo jerárquico.
- Archivo: src/Core/Controllers/RestController.php
  - Símbolo: RestController::updateMultiple()
  - Notas: Envuelve actualizaciones masivas en una transacción.
- Archivo: src/Core/Models/BaseModel.php
  - Símbolo: BaseModel::MODEL, BaseModel::HIERARCHY_FIELD_ID
  - Notas: Define la clave del modelo y la capacidad de jerarquía.
- Archivo: src/Core/Traits/HasPermissionsController.php
  - Símbolo: HasPermissionsController::assign_roles()
  - Notas: Provee el endpoint de asignación de permisos usado en el escenario.
