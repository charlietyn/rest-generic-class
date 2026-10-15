# Activar la autorización por canales con el script de migración

Desde v2.7.1 la biblioteca incluye `scripts/enable-channel-authorization.php`, el script usado para migrar kwikvet a la autorización por canales (v2.7.0+): permisos por nombre de ruta, middleware que falla cerrando, Gate de permisos efectivos, globales explícitos y catálogo limitado a las rutas protegidas.

```bash
php vendor/ronu/rest-generic-class/scripts/enable-channel-authorization.php --path=/ruta/al/proyecto
```

No es un comando artisan ni está en `bin` de `composer.json`: se ejecuta una vez por proyecto.

## Alcance

Está pensado para proyectos de la familia kwikvet: `ronu/rest-generic-class` más el módulo de seguridad generado. Antes de escribir nada comprueba que el proyecto tiene lo que `--apply` necesita; si falta algo, `--apply` no se ejecuta, ni siquiera con `--force`:

- los comandos `permissions:sync-from-routes` (`--guard`, `--dry-run`), `roles:assign-permissions` (`--by`, `--guard`, `--prefix`, `--no-create`; `--from` solo si alguna asignación usa un archivo) y `permission:cache-reset`;
- las columnas de la biblioteca en la tabla de permisos (`name`, `guard_name`, `module`, `route`, `type`, `model`, `restrict`, `action`) y una base de datos accesible;
- canales identificados por prefijo de URL (`admin/...`), no por subdominio, y todas las rutas protegidas bajo alguno de esos prefijos;
- `ronu/rest-generic-class` 2.7.0 o superior instalado.

## Qué inspecciona

Arranca el proyecto en un proceso hijo y lee la tabla de rutas real:

| Valor | Origen |
| --- | --- |
| Prefijos de canal | Primer segmento de la URI de cada ruta con el middleware de autorización (`--prefixes` lo sustituye). |
| Marcadores del catálogo | Alias de middleware que resuelven a `SpatieAuthorize`, directamente o envolviéndolo. |
| Guard | Parámetro de ese middleware, p. ej. `maybe.permission:api` (`--guard` lo sustituye). |
| Globales | Rutas protegidas cuyo nombre coincide con `--globals` (por defecto `*.auth.permissions,*.auth.user-profile`). |

Después informa de rutas protegidas sin nombre o con nombre repetido, simula la configuración nueva (toda ruta protegida debe resolver un permiso) e imprime los valores `REST_AUTHZ_*` que escribirá.

## Simulación por defecto

Sin `--apply` solo imprime el plan, incluido el diff de los archivos de rutas, y no escribe nada. Termina con código `1` si las comprobaciones encuentran problemas, `2` si aborta (por ejemplo, fuera de un proyecto Laravel) y `0` en otro caso.

## Orden de `--apply`

La activación es el último paso, para que nadie se quede fuera a mitad:

1. `composer.json`: sube la restricción a `^2.7` si admite versiones anteriores.
2. Archivos de rutas: envuelve cada ruta global en `Route::group(['permission_global' => true], fn () => ...)`. El informe muestra en cada archivo solo los nombres de su canal.
3. `permissions:sync-from-routes` con la configuración nueva inyectada como variables de entorno.
4. Asignación a roles (`--grant`).
5. `permission:cache-reset`.
6. Comprueba que algún rol tiene permisos; si no, se detiene (salvo `--force`).
7. Escribe `.env.example` y `.env` (esto activa la autorización) y refresca la configuración en caché.
8. Verifica la configuración que lee ahora el proyecto.

Al terminar, o si falla un paso, imprime la lista de pasos con `✔` (hecho), `✘` (el que falló) y `·` (no alcanzado), para saber desde dónde seguir.

## Asignación a roles

| Forma | Concede |
| --- | --- |
| `--grant=Administrator:admin.` | Todos los permisos cuyo nombre empieza por `admin.`. |
| `--grant=Client:@client-permissions.json` | La lista explícita del archivo (`.json`, `.yml` o `.yaml`), mediante `roles:assign-permissions --from`. |

Usa un archivo cuando los recursos están publicados en varios canales: conceder un prefijo entero como `client.` puede dar a un rol más de lo que debe. Un archivo fuera del proyecto se copia dentro para el comando y se borra después. Los roles deben existir.

```bash
php vendor/ronu/rest-generic-class/scripts/enable-channel-authorization.php --path=/ruta/al/proyecto --apply \
    --grant=Administrator:admin. --grant=Client:@client-permissions.json --grant=Veterinarian:mobile.
```

## Opciones

| Opción | Significado |
| --- | --- |
| `--path=` | Carpeta del proyecto (por defecto, la actual). |
| `--apply` | Escribe los cambios; sin ella, simula. |
| `--grant=Rol:prefijo` / `--grant=Rol:@archivo` | Concede permisos a un rol existente; repetible. |
| `--globals=` | Patrones de nombre de las rutas globales, separados por comas. |
| `--prefixes=` | Prefijos de canal, si la detección no acierta. |
| `--guard=` | Guard, si las rutas usan varios. |
| `--skip-sync` | No ejecuta la sincronización de permisos. |
| `--force` | Continúa pese a errores de comprobación o a no haber asignaciones, y sobrescribe valores existentes de `.env`. |
| `--php=` | Binario de PHP para los procesos hijos. |

## Tareas manuales

El script las imprime al final: restringir el login por canal, filtrar `GET /permissions` por canal, tests de regresión, documentación del proyecto y el orden de despliegue en otros entornos (sincronizar y asignar **antes** de cambiar su `.env`).

Consulta la [configuración](../02-configuration/00-configuration-reference.md) y las [notas de versión](../../../docs/release-notes-channel-authorization.md).
