# Escenarios límite y extremos

## 1) El cache de configuración oculta cambios de env

**Síntoma**
Los cambios en `LOG_QUERY` o `REST_STRICT_COLUMNS` no tienen efecto.

**Causa**
El paquete lee valores de env desde el archivo de configuración. Con config cache, los cambios de env se ignoran hasta regenerar el cache.

**Mitigación**
Ejecuta `php artisan config:clear` o `php artisan config:cache` después de actualizar valores env.

**Cómo reproducir**
1. Establece `LOG_QUERY=true` en `.env`.
2. Ejecuta `php artisan config:cache`.
3. Cambia `LOG_QUERY=false` y observa el comportamiento.

**Cómo probar**
Verifica `config('rest-generic-class.logging.query')` antes/después de limpiar el cache.

---

## 2) Workers de cola usan config o cache de permisos obsoletos

**Síntoma**
Workers de cola de larga duración se comportan como si la configuración o el cache de permisos anterior siguiera activo.

**Causa**
Los workers arrancan la app una vez y mantienen en memoria la config y el cache de permisos.

**Mitigación**
Reinicia los workers después de cambios de configuración o permisos. Si usas teams de Spatie, asegúrate de setear el team ID antes de que se ejecute `SpatieAuthorize`.

**Cómo reproducir**
1. Inicia un worker de cola.
2. Actualiza `filtering.max_depth` o asignaciones de permisos.
3. Ejecuta un job que use `BaseService` o autorización Spatie.

**Cómo probar**
Reinicia el worker y verifica que se respeten los nuevos cambios.

---

## 3) Colisiones de concurrencia en actualizaciones masivas

**Síntoma**
Dos admins actualizan el mismo registro simultáneamente y se pierden cambios.

**Causa**
`update_multiple()` aplica actualizaciones fila por fila sin bloqueo a nivel de registro.

**Mitigación**
Agrega bloqueo optimista a nivel de aplicación (por ejemplo, checks de `updated_at`) o usa bloqueo a nivel de DB en tus overrides del servicio.

**Cómo reproducir**
1. Envía dos solicitudes `update-multiple` con IDs solapados.
2. Observa el comportamiento de last-write-wins.

**Cómo probar**
Escribe un test de integración que envíe solicitudes concurrentes y verifique valores finales.

---

## 4) Payloads grandes o explosión de filtros

**Síntoma**
Las solicitudes fallan con `Maximum conditions (...) exceeded` o alcanzan límites de memoria.

**Causa**
El motor de filtrado aplica `filtering.max_conditions` y `filtering.max_depth` para proteger la base de datos.

**Mitigación**
- Divide solicitudes en partes más pequeñas.
- Incrementa `filtering.max_conditions` solo cuando sea necesario.

**Cómo reproducir**
Envía un `oper` con >100 condiciones o relaciones anidadas >5 niveles.

**Cómo probar**
Usa una prueba de carga que aumente el tamaño de `oper` hasta disparar la excepción.

---

## 5) Árboles jerárquicos profundos causan timeouts

**Síntoma**
Las solicitudes de listado jerárquico expiran con árboles grandes.

**Causa**
El modo jerárquico carga el árbol completo salvo que se use `max_depth` o paginación.

**Mitigación**
- Establece `max_depth`.
- Pagina raíces usando `pagination`.
- Reduce el dataset con filtros `oper`.

**Cómo reproducir**
Llama endpoints de listado con `hierarchy=true` en un dataset auto-referenciado grande.

**Cómo probar**
Benchmark de tiempos de respuesta con y sin `max_depth` y paginación de raíces.

---

## 6) Desajuste de permisos multi-tenant

**Síntoma**
Usuarios de un tenant acceden a permisos de otro tenant.

**Causa**
El PermissionRegistrar de Spatie usa un team ID para acotar permisos. Si no se setea, cae en la clave global de cache.

**Mitigación**
Setea el team ID antes de que se ejecute el middleware `SpatieAuthorize` (por ejemplo, en un middleware de tenant).

**Cómo reproducir**
Habilita teams en Spatie permissions y omite la asignación del team ID antes de la autorización.

**Cómo probar**
Escribe un test que setee distintos team IDs y verifique permisos.

---

## 7) Rate limiting vs. filtros pesados

**Síntoma**
Clientes alcanzan límites de rate cuando ejecutan filtros costosos o consultas con relaciones anidadas.

**Causa**
Los filtros `oper` complejos y la carga de relaciones pueden ser costosos y producir requests más lentas.

**Mitigación**
- Agrega rate limits en la app host.
- Cachea consultas comunes.
- Prefiere `select` para limitar el payload.

**Cómo reproducir**
Llama repetidamente endpoints de listado con árboles `oper` complejos y `relations`.

**Cómo probar**
Mide tiempo de respuesta y cantidad de requests bajo carga.

---

## 8) `orderby` por campos de relaciones — casos extremos de semántica y límites

**Síntoma**
- `orderby` sobre una ruta con punto (`user.role.name`) elige silenciosamente una sola fila cuando la relación es `*-to-many`.
- Una solicitud devuelve 400 con `Maximum orderby relation depth (...) exceeded` o `Relation '...' is not allowed for ordering`.
- Una entrada literal `"tabla.columna"` sin un JOIN renderiza SQL inesperado.

**Causa**
- `orderby` usa un subquery escalar con `LIMIT 1`. Para `hasMany` y `belongsToMany`, el motor toma de forma determinista la primera fila hija que coincida (orden por defecto: la PK de la relación). No agrega.
- Las rutas de relación deben resolver a métodos declarados en `const RELATIONS`; la profundidad está acotada por `rest-generic-class.filtering.max_depth` (default `5`).
- Cuando el primer segmento de una entrada con punto **no** es un método del modelo, el valor se pasa al builder tal cual, por retrocompatibilidad con consumidores que pre-ensamblan un JOIN.

**Mitigación**
- Para "ordenar por agregado de hijos" (p. ej., máximo rating de reviews, conteo de posts), expone una columna precomputada/computada en el padre, o aplica el orden dentro de una vista con conciencia de `oper`.
- Declara las relaciones permitidas en `const RELATIONS` y mantén las rutas poco profundas.
- Para ordenamiento literal `"tabla.columna"`, asegúrate de que la consulta lleve el JOIN correspondiente; en caso contrario, usa la forma con notación de punto soportada.
- Las relaciones polimórficas se manejan automáticamente — el predicado `*_type` lo agrega Eloquent al construir el subquery.

**Cómo reproducir**
1. Ordena usuarios por `posts.title` y observa orden determinista pero no agregado.
2. Envía `orderby=[{"a.b.c.d.e.f.g":"asc"}]` y observa el error de profundidad.
3. Envía `orderby=[{"role.name":"asc"}]` contra un modelo donde `role()` existe pero `'role'` no está en `RELATIONS` y observa el 400.

**Cómo probar**
Verifica que el SQL generado contenga el subquery escalar esperado (un `limit 1` por segmento) y que el tamaño del resultado para ordenar por `*-to-many` coincida con el conteo de filas padre (sin duplicados).

---

## 9) Errores por relación/operador inválido

**Síntoma**
Las solicitudes devuelven error 400 indicando que la relación u operador es inválido.

**Causa**
El paquete valida relaciones contra `RELATIONS` y operadores contra la allowlist.

**Mitigación**
Agrega relaciones faltantes a `RELATIONS` y verifica que los operadores estén en la allowlist.

**Cómo reproducir**
Usa `relations=["internalLogs"]` cuando no está en la allowlist.

**Cómo probar**
Escribe un test que valide el mensaje de error cuando se solicita una relación prohibida.

[Volver al índice de documentación](../index.md)

## Evidencia
- Archivo: config/rest-generic-class.php
  - Símbolo: claves de configuración en `logging` y `filtering`
  - Notas: Muestra config basada en env y límites de filtrado usados en los escenarios.
- Archivo: src/Core/Services/BaseService.php
  - Símbolo: BaseService::applyOperTree(), BaseService::listHierarchy(), BaseService::normalizeHierarchyParams()
  - Notas: Confirma enforcement de profundidad/condiciones y comportamiento de jerarquía.
- Archivo: src/Core/Middleware/SpatieAuthorize.php
  - Símbolo: SpatieAuthorize::handle()
  - Notas: Usa PermissionRegistrar de Spatie y checks de permisos por guard.
