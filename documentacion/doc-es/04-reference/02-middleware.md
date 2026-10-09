# Middleware

## TransformData

`Ronu\RestGenericClass\Core\Middleware\TransformData` valida solicitudes POST/PUT/PATCH instanciando una clase `BaseFormRequest` y ejecutando `validate_request()`.

## InjectRequestParams

`Ronu\RestGenericClass\Core\Middleware\InjectRequestParams` inyecta pares clave/valor en la request desde parámetros del middleware. Soporta:

- Namespacing (`ns=_meta`).
- Forzar sobreescritura (`force`).
- Casting de tipos (booleanos, números, JSON, base64, null).

## SpatieAuthorize

`SpatieAuthorize` resuelve permisos y los comprueba con `$user->can()`.

[Volver al índice de documentación](../index.md)

## Evidencia
- Archivo: src/Core/Middleware/TransformData.php
  - Símbolo: TransformData::handle()
  - Notas: Valida datos de request vía `BaseFormRequest`.
- Archivo: src/Core/Middleware/InjectRequestParams.php
  - Símbolo: InjectRequestParams::handle(), InjectRequestParams::castValue()
  - Notas: Inyecta y casteo de parámetros de request.
- Archivo: src/Core/Middleware/SpatieAuthorize.php
  - Símbolo: SpatieAuthorize::handle()
  - Notas: Deriva permisos y aplica autorización.

## Autorización por canales (v2.6.0)

El middleware y la sincronización comparten ahora `RouteMetaResolver`. La autorización utiliza `$user->can($canonicalName)` con Spatie o con el Gate opcional de permisos efectivos. Registra antes la autenticación, por ejemplo `auth:api` seguido de tu alias `auto.authorize:api`.

**Seguridad:** con la configuración legacy, un prefijo distinto del guard deja la ruta sin resolver y `on_unresolved=allow` omite la autorización. Configura los prefijos de canales explícitamente y usa `deny`. Si falta el usuario o el permiso resuelto, responde 403; con `deny`, también lo hace para rutas no resueltas. En debug se muestra el permiso o el motivo; en producción solo `Forbidden`.

El modo `route_name` admite closures con nombre, incluidos los sufijos `.create` y `.edit`. El modo derivado conserva las exclusiones del catálogo para acciones UI create/edit y rutas sin controlador. El middleware ahora respeta la versión igual que la sincronización: resincroniza y revisa las asignaciones si tus rutas tienen metadatos de versión.

Consulta la [configuración](../02-configuration/00-configuration-reference.md) y el [despliegue por canales](../03-usage/03-edge-and-extreme-scenarios.md) para activar los cambios en orden.

## Fase 2: compatibilidad legacy y control del catálogo

Con `route_prefixes=null`, la inferencia de módulo vuelve a las reglas de nombre exacto y número de segmentos de v2.5.0. Los nombres de módulo snake_case solo se aceptan con prefijos de canales explícitos. Si sincronizaste nombres legacy con v2.6.0, resincroniza y revisa las asignaciones a los nombres restaurados.

`permissions.authorization.catalog_middleware` filtra únicamente `RoutePermissionRefresher`. Por ejemplo, `['maybe.permission']` coincide con `maybe.permission:api`; un marcador FQCN coincide con ese string de clase, con o sin parámetros. Los marcadores son prefijos literales de strings, sin expansión de alias/grupos; se ignoran middleware que no sean string. `null` incluye todas las rutas del ámbito y `[]` no incluye ninguna. El resolver y `SpatieAuthorize` no aplican ese filtro: excluir una ruta del catálogo no permite saltarse la comprobación de permisos, ni siquiera con `on_unresolved=allow`.

`explicit_globals=true` guarda como globales (`restrict=false`) solo las rutas cuya acción lleva `'permission_global' => true` (atributo de grupo o `setAction()`, no `->defaults()`); el resto queda restringido, así que una ruta nueva fuera de un módulo ya no se vuelve global sin que nadie lo decida. `false` (por defecto) conserva los globales por módulo. El Gate sigue leyendo las restricciones persistidas. Ninguna opción elimina permisos antiguos; las filas excluidas, sus restricciones y asignaciones quedan intactas. Revisa y elimina manualmente las filas obsoletas. No se introduce una opción `--prune`.
