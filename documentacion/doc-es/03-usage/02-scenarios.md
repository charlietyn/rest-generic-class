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

---

## Escenario 5: Auditar permisos grandes con compresion wildcard

**Objetivo**
Devolver payloads de permisos legibles para pantallas admin senior, reportes o auditorias sin cambiar los permisos reales almacenados por Spatie.

**Configuracion**
- Usa `HasPermissionsController` en tu controlador de permisos.
- Mapea los endpoints de lectura desde las rutas de tu aplicacion:

```php
Route::get('/api/permissions/roles', [PermissionController::class, 'get_permissions_by_roles']);
Route::get('/api/permissions/users', [PermissionController::class, 'get_permissions_by_users']);
```

**Pasos**
1. Llama el endpoint de lectura con `compress=true`.
2. Usa `roles[]` o `users[]` y elige `by` segun tus identificadores.
3. Agrega `expand=true` solo cuando el cliente necesite los nombres expandidos para drill-down o exportacion.

**Codigo de ejemplo**
```http
GET /api/permissions/roles?roles[]=admin&by=name&guard=api&compress=true
```

```json
{
  "ok": true,
  "data": [
    {
      "role": "admin",
      "guard": "api",
      "permissions": [
        "security.*",
        "sales.order.*",
        "reports.dashboard.index"
      ],
      "stats": {
        "original_count": 24,
        "compressed_count": 3,
        "compression_ratio": 8
      }
    }
  ]
}
```

Con `expand=true`, la misma respuesta comprimida incluye los nombres expandidos:

```http
GET /api/permissions/users?users[]=alice@example.com&by=email&guard=api&compress=true&expand=true
```

**Notas**
- `security.*` significa que el sujeto tiene todos los permisos del universo actual para el modulo `security`.
- `security.user.*` significa todos los actions conocidos para `security.user`, no una regla de autorizacion almacenada en la base de datos.
- `compress_global=true` habilita `*`; mantenlo deshabilitado salvo que el cliente sea confiable y espere resumenes globales de auditoria.

**Errores comunes**
- Tratar los strings comprimidos como permisos para escribir de vuelta en Spatie.
- Habilitar `compress_global=true` en respuestas amplias de cliente sin una razon de producto.

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
  - Símbolo: HasPermissionsController::assign_roles(), HasPermissionsController::get_permissions_by_roles(), HasPermissionsController::get_permissions_by_users()
  - Notas: Provee endpoints de asignacion y lectura comprimida usados en los escenarios.
- Archivo: src/Core/Support/Permissions/
  - Simbolo: PermissionCompressor
  - Notas: Comprime nombres planos de permisos en strings wildcard de presentacion.
