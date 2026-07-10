# Plan restante de refactor de permisos

> Estado: cortes 18-23 ejecutados. Ver `docs/permissions-public-api.md` para el
> cierre de contratos, matriz de compatibilidad y guia de migracion. Cada corte
> quedo cubierto con tests unitarios directos y la suite completa en verde.
>
> - [x] Corte 18: `PermissionUniverseResolver`
> - [x] Corte 19: `UserPermissionReader`
> - [x] Corte 20: `RolePermissionReader`
> - [x] Corte 21: `RoleInputResolver`, `TargetPermissionResolver`,
>   `RolePermissionAssignmentService`, `RoutePermissionRefresher`
>   (+ `PermissionListNormalizer` compartido)
> - [x] Corte 22: `RequiredPermissionResolver`, `PermissionDecisionEvaluator`
> - [x] Corte 23: documentacion de contratos y guia de migracion

## Estado actual

Ya quedaron aisladas estas piezas:

- Resolucion de roles por contrato: `UserRolesResolver`.
- Compresion de permisos: `PermissionCompressor`.
- Payload autenticado: `PermissionPayloadBuilder`.
- Filtros de permisos por `guard`, `module` y `entity`: `PermissionFilter`.

Los traits publicos siguen siendo la capa compatible para consumidores:

- `HasReadableUserPermissions`
- `HasReadableRolePermissions`
- `HasPermissionsService`
- `HasPermissionsController`

## Corte 18: universo de compresion

Objetivo: sacar de `HasReadableUserPermissions` la consulta que arma el universo de permisos usado por `effectivePermissionsCompressed()`.

Pasos:

1. Crear `PermissionUniverseResolver`.
2. Mover `permissionCompressionUniverse()` al resolver.
3. Mantener `effectivePermissionsCompressed()` como metodo publico compatible.
4. Cubrir filtros por `guard`, `modules` y `entities`, incluyendo `module.entity`.
5. Verificar `AuthenticatedPermissionsPayloadTest`, `PermissionPayloadBuilderTest` y suite completa.

Riesgo: medio. Toca consultas contra el modelo configurado de permisos.

## Corte 19: servicio de lectura de permisos de usuario

Objetivo: adelgazar `HasReadableUserPermissions` para que deje de coordinar permisos directos, roles y compresion.

Pasos:

1. Crear `UserPermissionReader`.
2. Mover calculo de `effectivePermissions()` al reader.
3. Mantener `directPermissions()`, `rolePermissions()`, `getEnabledPermissionsViaRoles()`, `effectivePermissions()` y `permissionsFiltered()` como fachadas.
4. Cubrir usuario con permisos directos, permisos por rol, `guard` y deduplicacion por `id`.
5. Mantener `UserRolesResolver` como frontera de contratos.

Riesgo: medio-alto. Es una ruta central para payload, controller y servicios.

## Corte 20: servicio de permisos de rol

Objetivo: separar en un colaborador la lectura de permisos de rol y globales no restringidos.

Pasos:

1. Crear `RolePermissionReader`.
2. Mover `allPermissions()` y la combinacion con `notRestricted()`.
3. Mantener `HasReadableRolePermissions::allPermissions()` y `provideRolePermissions()` compatibles.
4. Cubrir merge de permisos propios con globales no restringidos y deduplicacion por `id`.
5. Cubrir que `permissionsFiltered()` sigue delegando en `PermissionFilter`.

Riesgo: medio. Depende de Spatie/config y de scopes del modelo de permiso.

## Corte 21: adelgazar `HasPermissionsService`

Objetivo: dividir el trait de servicio, que sigue concentrando escenarios, asignaciones, resolucion de roles/permisos y refresh de rutas.

Pasos:

1. Extraer resolucion de roles a `RoleInputResolver`.
2. Extraer resolucion de permisos destino a `TargetPermissionResolver`.
3. Extraer asignacion/sincronizacion de roles a `RolePermissionAssignmentService`.
4. Extraer `refreshPermissions()` a `RoutePermissionRefresher`.
5. Mantener los metodos publicos de `HasPermissionsService` como fachada.

Riesgo: alto. Es el siguiente god node de permisos y debe hacerse en varios cortes.

## Corte 22: middleware `SpatieAuthorize`

Objetivo: separar resolucion de permisos requeridos de la evaluacion del usuario.

Pasos:

1. Crear `RequiredPermissionResolver` para override, route name, action y verbo HTTP.
2. Crear `PermissionDecisionEvaluator` para `any`/`all`.
3. Mantener `SpatieAuthorize::handle()` y comportamiento HTTP intactos.
4. Cubrir override explicito, strict mode, `any`, `all` y usuario no autenticado.
5. Evitar dependencia directa de DB; seguir usando cache/registrar de Spatie.

Riesgo: alto. Cambios aqui afectan seguridad de rutas.

## Corte 23: contratos y documentacion final

Objetivo: cerrar permisos como API estable documentada.

Pasos:

1. Documentar contratos obligatorios y opcionales.
2. Marcar clases internas como soporte no estable si aplica.
3. Agregar matriz de compatibilidad: Spatie, contratos propios, rutas, payload y middleware.
4. Ejecutar `graphify . --update` para comparar god nodes.
5. Publicar guia de migracion para consumidores.

Riesgo: bajo-medio. Es documentacion, pero define expectativas publicas.
