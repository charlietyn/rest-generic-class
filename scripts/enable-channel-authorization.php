<?php
declare(strict_types=1);

/**
 * enable-channel-authorization.php
 *
 * Migrates a Laravel project that uses ronu/rest-generic-class to channel-scoped authorization
 * (v2.7.0+): route-name permissions, fail-closed middleware, the HasReadableUserPermissions Gate,
 * explicit globals and a catalog limited to protected routes.
 *
 * It inspects the project by booting it, so everything it proposes comes from the real route table:
 *   - route prefixes   = first URI segment of every route behind the authorization middleware
 *   - catalog markers  = middleware aliases that resolve to SpatieAuthorize (directly or by wrapping it)
 *   - guard            = the parameter given to that middleware (e.g. maybe.permission:api)
 *   - globals          = protected routes whose name matches --globals (marked with permission_global)
 *
 * Scope: projects of the kwikvet family (ronu/rest-generic-class + the generated security module).
 * Before writing anything it verifies that the project has what --apply needs: the commands
 * permissions:sync-from-routes / roles:assign-permissions (with the options used here) and
 * permission:cache-reset, the library's columns on the permissions table, a reachable database, and
 * channels identified by URL prefix (not by subdomain). A missing requirement blocks --apply, even
 * with --force, so a project outside the family is never left half migrated.
 *
 * Default mode is a dry run: it prints the plan and the route-file diffs and writes nothing.
 *
 * Usage (installed with the library):
 *   php vendor/ronu/rest-generic-class/scripts/enable-channel-authorization.php [--path=/project] [--apply]
 *        [--grant=Role:prefix | --grant=Role:@permissions.json ...]
 *        [--globals=*.auth.permissions,*.auth.user-profile]
 *        [--prefixes=admin,client] [--guard=api] [--skip-sync] [--force] [--php=/usr/bin/php]
 *
 * --grant=Role:prefix grants every permission whose name starts with the prefix. When a resource is
 * published in several channels a whole prefix may grant too much: --grant=Role:@file.json (or .yml)
 * grants an explicit flat list of permission names instead (roles:assign-permissions --from).
 *
 * Example:
 *   php enable-channel-authorization.php --path=D:/www/kwikvet
 *   php enable-channel-authorization.php --path=D:/www/kwikvet --apply \
 *       --grant=Administrator:admin. --grant=Client:@client-permissions.json --grant=Veterinarian:mobile.
 *
 * --apply order (activation is LAST, so nobody is locked out halfway):
 *   1. composer.json constraint  2. mark globals in route files  3. sync permissions (with the new
 *   configuration injected as environment variables)  4. grant roles  5. reset the permission cache
 *   6. write .env.example / .env (this activates enforcement)  7. verify.
 * The completed steps are listed at the end, or when a step fails, to know where to resume.
 *
 * Not automated, printed as follow-ups: per-channel login restriction and per-channel /permissions
 * filtering in the project's auth controller, tests and project documentation.
 */

const MIN_VERSION = '2.7.0';
const DEFAULT_GLOBALS = '*.auth.permissions,*.auth.user-profile';
const AUTHORIZE_CLASS = 'Ronu\\RestGenericClass\\Core\\Middleware\\SpatieAuthorize';
// Commands (and the options this script passes) that the kwikvet family generates in its security module.
const REQUIRED_COMMANDS = [
    'permissions:sync-from-routes' => ['guard', 'dry-run'],
    'roles:assign-permissions' => ['by', 'guard', 'prefix', 'no-create', 'from'],
    'permission:cache-reset' => [],
];
// Columns RoutePermissionRefresher writes on top of Spatie's permissions table.
const REQUIRED_COLUMNS = ['name', 'guard_name', 'module', 'route', 'type', 'model', 'restrict', 'action'];
const ENV_KEYS = [
    'REST_AUTHZ_ROUTE_PREFIXES', 'REST_AUTHZ_NAMING', 'REST_AUTHZ_ON_UNRESOLVED', 'REST_AUTHZ_REGISTER_GATE',
    'REST_AUTHZ_GATE_GUARD', 'REST_AUTHZ_EXPLICIT_GLOBALS', 'REST_AUTHZ_CATALOG_MIDDLEWARE',
];

require_once __DIR__ . '/channel-authorization-functions.php';

// ---------------------------------------------------------------------------------------------
// Child mode: boot the project and print what it knows as JSON. Runs in a separate process so the
// parent can inject the proposed configuration through environment variables.
// ---------------------------------------------------------------------------------------------
if (in_array('--__inspect', $argv, true)) {
    $path = substr((string) current(array_filter($argv, fn ($a) => str_starts_with($a, '--path='))), 7);
    echo json_encode(inspect($path), JSON_UNESCAPED_SLASHES);
    exit(0);
}

function inspect(string $path): array
{
    chdir($path);
    require $path . '/vendor/autoload.php';
    $app = require $path . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $router = $app->make('router');
    $markers = [];
    foreach ($router->getMiddleware() as $alias => $class) {
        if (wrapsAuthorize($class)) {
            $markers[] = $alias;
        }
    }

    $cfg = config('rest-generic-class.permissions.authorization', []);
    $modules = class_exists(Nwidart\Modules\Facades\Module::class)
        ? Nwidart\Modules\Facades\Module::toCollection()->map->getName()->values()->all() : [];
    $resolver = $app->make(Ronu\RestGenericClass\Core\Resolvers\RouteMetaResolver::class);

    $routes = [];
    $guards = [];
    foreach ($router->getRoutes() as $route) {
        $mw = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
        $protected = false;
        foreach ($mw as $m) {
            [$alias, $params] = array_pad(explode(':', $m, 2), 2, null);
            if (in_array($alias, $markers, true) || $alias === AUTHORIZE_CLASS) {
                $protected = true;
                $guards[] = $params ? explode(',', $params)[0] : (string) config('auth.defaults.guard');
            }
        }
        $resolved = null;
        if ($protected) {
            $guard = end($guards) ?: 'api';
            $meta = $resolver->resolveFromRoute($route, $guard, $modules, $cfg);
            $resolved = $meta?->canonicalName;
        }
        $routes[] = [
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'protected' => $protected,
            'global' => (bool) ($route->getAction()['permission_global'] ?? false),
            'resolved' => $resolved,
        ];
    }

    $db = ['ok' => false];
    try {
        $permTable = config('permission.table_names.permissions');
        $pivot = config('permission.table_names.role_has_permissions');
        $conn = (new (config('permission.models.permission')))->getConnectionName();
        $q = Illuminate\Support\Facades\DB::connection($conn);
        $db = [
            'ok' => true,
            'permissions' => $q->table($permTable)->count(),
            'role_grants' => $q->table($pivot)->count(),
            'roles' => $q->table(config('permission.table_names.roles'))->pluck('name')->all(),
        ];
    } catch (Throwable $e) {
        $db['error'] = $e->getMessage();
    }

    // Requirements of the kwikvet family: the sync/assign commands live in the generated security
    // module, not in the library, and the catalog writes the library's extra permission columns.
    $commands = Illuminate\Support\Facades\Artisan::all();
    $requirements = ['commands' => [], 'options' => [], 'columns' => []];
    foreach (REQUIRED_COMMANDS as $command => $options) {
        $requirements['commands'][$command] = isset($commands[$command]);
        foreach ($options as $option) {
            $requirements['options']["$command --$option"] = isset($commands[$command])
                && $commands[$command]->getDefinition()->hasOption($option);
        }
    }
    try {
        $permModel = new (config('permission.models.permission'));
        $schema = Illuminate\Support\Facades\Schema::connection($permModel->getConnectionName());
        $table = config('permission.table_names.permissions');
        foreach (REQUIRED_COLUMNS as $column) {
            $requirements['columns'][$column] = $schema->hasColumn($table, $column);
        }
    } catch (Throwable $e) {
        $requirements['columns_error'] = $e->getMessage();
    }

    $channels = [];
    foreach ((array) config('app-context.channels', []) as $id => $channel) {
        foreach ((array) ($channel['path_prefixes'] ?? []) as $prefix) {
            $channels[trim((string) $prefix, '/')] = $id;
        }
    }
    $moduleSegments = array_merge($modules, array_map(fn ($m) => Illuminate\Support\Str::snake($m), $modules));

    return [
        'requirements' => $requirements,
        'app_context_prefixes' => $channels,
        'module_segments' => $moduleSegments,
        'version' => Composer\InstalledVersions::getPrettyVersion('ronu/rest-generic-class'),
        'config_cached' => $app->configurationIsCached(),
        'cfg' => $cfg,
        'markers' => $markers,
        'guards' => array_values(array_unique($guards)),
        'routes' => $routes,
        'db' => $db,
    ];
}

/** True when the middleware class is SpatieAuthorize, extends it, or delegates to it (e.g. MaybePermission). */
function wrapsAuthorize(string $class): bool
{
    if (!class_exists($class)) {
        return false;
    }
    if ($class === AUTHORIZE_CLASS || is_subclass_of($class, AUTHORIZE_CLASS)) {
        return true;
    }
    $file = (new ReflectionClass($class))->getFileName();
    return $file && str_contains((string) file_get_contents($file), 'SpatieAuthorize');
}

// ---------------------------------------------------------------------------------------------
// Parent mode.
// ---------------------------------------------------------------------------------------------
$opt = parseOptions($argv);
$path = rtrim(str_replace('\\', '/', realpath($opt['path']) ?: $opt['path']), '/');
$php = $opt['php'];
$apply = $opt['apply'];

if (!is_file("$path/artisan") || !is_file("$path/vendor/autoload.php")) {
    fail("$path is not an installed Laravel project (artisan / vendor/autoload.php not found).");
}

title('1. Inspección');
$now = runInspect($php, $path, []);
$installed = ltrim((string) $now['version'], 'v');
line("ronu/rest-generic-class instalado: {$now['version']}");
if (version_compare($installed, MIN_VERSION, '<')) {
    fail('Se necesita ' . MIN_VERSION . ' o superior. Ejecuta primero: composer require ronu/rest-generic-class:^2.7');
}
if ($now['config_cached']) {
    warn('La configuración está en caché (bootstrap/cache/config.php): los cambios de .env no se leen hasta config:clear/config:cache.');
}
if (!$now['markers']) {
    fail('Ningún alias de middleware resuelve a SpatieAuthorize: el proyecto no usa la autorización de la biblioteca.');
}

$protected = array_values(array_filter($now['routes'], fn ($r) => $r['protected']));
$prefixes = $opt['prefixes'] ?: array_values(array_unique(array_map(fn ($r) => explode('/', trim($r['uri'], '/'))[0], $protected)));
$guard = $opt['guard'] ?: ($now['guards'][0] ?? 'api');
if (count($now['guards']) > 1 && !$opt['guard']) {
    warn('Las rutas usan varios guards (' . implode(', ', $now['guards']) . "); se usará '$guard'. Usa --guard para elegir.");
}
line('Middleware de autorización: ' . implode(', ', $now['markers']));
line('Rutas protegidas: ' . count($protected));
line('Prefijos (canales): ' . implode(', ', $prefixes));
line("Guard: $guard");

// Preflight: anything here that fails would leave --apply half done, so it blocks before any write.
// --force does NOT skip these: they are missing pieces, not judgement calls.
title('1b. Requisitos del proyecto (familia kwikvet)');
$blocking = 0;
$req = $now['requirements'];
foreach ($req['commands'] as $command => $exists) {
    $exists ? ok("Comando $command") : (err("Falta el comando $command (lo genera el módulo de seguridad del proyecto)") ?: $blocking++);
}
$fileGrants = array_filter($opt['grant'], fn ($g) => $g['file'] !== null);
foreach ($req['options'] as $option => $exists) {
    if ($option === 'roles:assign-permissions --from' && !$fileGrants) {
        continue; // only needed by --grant=Role:@file
    }
    if (!$exists && ($req['commands'][explode(' ', $option)[0]] ?? false)) {
        err("El comando no acepta la opción $option (versión distinta del módulo de seguridad)");
        $blocking++;
    }
}
if (isset($req['columns_error'])) {
    err('No se pudo leer la tabla de permisos: ' . $req['columns_error']);
    $blocking++;
} else {
    $missingColumns = array_keys(array_filter($req['columns'], fn ($exists) => !$exists));
    $missingColumns
        ? (err('A la tabla de permisos le faltan columnas: ' . implode(', ', $missingColumns)) ?: $blocking++)
        : ok('Tabla de permisos con las columnas ' . implode(', ', REQUIRED_COLUMNS));
}
if (!$now['db']['ok']) {
    err('Sin conexión a la base de datos: ' . ($now['db']['error'] ?? '?'));
    $blocking++;
}
foreach ($prefixes as $prefix) {
    if ($prefix === '' || str_starts_with($prefix, '{')) {
        err("Prefijo de canal no válido: '$prefix' (hay rutas protegidas sin prefijo fijo de canal)");
        $blocking++;
    } elseif (in_array($prefix, $now['module_segments'], true)) {
        err("'$prefix' es un módulo, no un canal: el proyecto parece distinguir canales por subdominio. Usa --prefixes o adapta el proyecto.");
        $blocking++;
    } elseif ($now['app_context_prefixes'] && !isset($now['app_context_prefixes'][$prefix])) {
        warn("'$prefix' no está en ningún path_prefixes de config/app-context.php");
    }
}
$uncovered = array_filter($protected, fn ($r) => !in_array(explode('/', trim($r['uri'], '/'))[0], $prefixes, true));
if ($uncovered) {
    listSome($uncovered, 'err', 'Rutas protegidas fuera de los prefijos (responderían 403 siempre)', fn ($r) => $r['uri']);
    $blocking++;
}
$blocking ? err("$blocking requisito(s) sin cumplir: --apply no escribirá nada.") : ok('El proyecto cumple los requisitos.');

$proposed = [
    'REST_AUTHZ_ROUTE_PREFIXES' => implode(',', $prefixes),
    'REST_AUTHZ_NAMING' => 'route_name',
    'REST_AUTHZ_ON_UNRESOLVED' => 'deny',
    'REST_AUTHZ_REGISTER_GATE' => 'true',
    'REST_AUTHZ_GATE_GUARD' => $guard,
    'REST_AUTHZ_EXPLICIT_GLOBALS' => 'true',
    'REST_AUTHZ_CATALOG_MIDDLEWARE' => implode(',', $now['markers']),
];

title('2. Comprobaciones de las rutas protegidas');
$problems = 0;
$unnamed = array_filter($protected, fn ($r) => !$r['name']);
$problems += count($unnamed);
listSome($unnamed, 'err', 'Rutas protegidas SIN nombre (con naming=route_name devolverán siempre 403)', fn ($r) => $r['uri']);
$outside = array_filter($protected, fn ($r) => $r['name'] && !startsWithAny($r['name'], array_map(fn ($p) => "$p.", $prefixes)));
listSome($outside, 'warn', 'Nombres que no empiezan por su canal (pueden chocar entre canales)', fn ($r) => "{$r['name']} ({$r['uri']})");
$names = array_count_values(array_filter(array_map(fn ($r) => $r['name'], $protected)));
foreach (array_filter($names, fn ($n) => $n > 1) as $name => $n) {
    err("Nombre de ruta repetido en $n rutas protegidas (compartirían permiso): $name");
    $problems++;
}
if (!$problems) {
    ok('Todas las rutas protegidas tienen un nombre único.');
}

title('3. Permisos globales (permission_global)');
$globalPatterns = $opt['globals'];
$targets = array_values(array_filter($protected, fn ($r) => $r['name'] && matchesAny($r['name'], $globalPatterns)));
line('Patrones: ' . implode(', ', $globalPatterns));
if (!$targets) {
    warn('Ninguna ruta protegida coincide con los patrones: no habrá permisos globales.');
}
$routeFiles = array_merge(glob("$path/routes/*.php") ?: [], glob("$path/Modules/*/Routes/*.php") ?: []);
$patches = planRoutePatches($routeFiles, $targets, $prefixes, $globalPatterns);
foreach ($targets as $r) {
    line(($r['global'] ? '  ya marcada  ' : '  a marcar    ') . $r['name']);
}
foreach ($patches as $file => $p) {
    line('');
    line('--- ' . relative($path, $file));
    foreach ($p['diff'] as $d) {
        line("  - {$d[0]}");
        line("  + {$d[1]}");
    }
}
$patchedNames = array_merge([], ...array_values(array_map(fn ($p) => $p['names'], $patches)));
foreach (array_diff(array_column(array_filter($targets, fn ($r) => !$r['global']), 'name'), $patchedNames) as $missing) {
    warn("No se encontró la declaración de $missing en los archivos de rutas: márcala a mano (ver docs).");
}

title('4. Simulación con la configuración nueva');
$sim = runInspect($php, $path, $proposed);
$simProtected = array_filter($sim['routes'], fn ($r) => $r['protected']);
$unresolved = array_filter($simProtected, fn ($r) => $r['resolved'] === null);
line('Permisos que exigirá el middleware: ' . count(array_unique(array_filter(array_column($simProtected, 'resolved')))));
listSome($unresolved, 'err', 'Rutas protegidas sin permiso resoluble (403 siempre)', fn ($r) => ($r['name'] ?? '-') . " ({$r['uri']})");
if (!$unresolved) {
    ok('Todas las rutas protegidas resuelven un permiso.');
}

title('5. Variables de entorno');
foreach ($proposed as $k => $v) {
    line("$k=$v");
}

$grants = $opt['grant'];
title('6. Asignación a roles');
if (!$grants) {
    warn('Sin --grant. Si ningún rol tiene permisos al activar, NADIE podrá entrar (on_unresolved=deny + Gate).');
} else {
    foreach ($grants as $g) {
        $known = $now['db']['ok'] && !in_array($g['role'], $now['db']['roles'], true) ? '  (¡el rol no existe!)' : '';
        line($g['file'] !== null
            ? "{$g['role']} ← permisos listados en {$g['file']}$known"
            : "{$g['role']} ← permisos que empiezan por '{$g['prefix']}'$known");
    }
}

if (!$apply) {
    title('Modo simulación: no se ha escrito nada.');
    line('Revisa el plan y vuelve a ejecutar con --apply (y --grant=Rol:prefijo o --grant=Rol:@archivo.json por cada rol).');
    exit($blocking || $problems || $unresolved ? 1 : 0);
}

// ------------------------------------------- APPLY -------------------------------------------
if ($blocking) {
    fail("El proyecto no cumple $blocking requisito(s) (sección 1b). No se ha escrito nada.");
}
if (($problems || $unresolved) && !$opt['force']) {
    fail('Hay errores en las comprobaciones 2 o 4. Corrígelos o usa --force.');
}
if ($grants && $now['db']['ok']) {
    foreach ($grants as $g) {
        if (!in_array($g['role'], $now['db']['roles'], true)) {
            fail("El rol '{$g['role']}' no existe (roles:assign-permissions lo crearía). Revisa --grant.");
        }
    }
}

title('APLICANDO');
startProgress([
    'composer.json',
    'rutas globales (permission_global)',
    $opt['skip_sync'] ? 'sincronización (omitida con --skip-sync)' : 'sincronización de permisos',
    'asignación a roles',
    'caché de permisos',
    'comprobación de asignaciones',
    'activación (.env.example / .env)',
    'verificación',
]);

// 1. composer.json constraint
$composerFile = "$path/composer.json";
$composer = file_get_contents($composerFile);
if (preg_match('/"ronu\/rest-generic-class"\s*:\s*"([^"]+)"/', $composer, $m) && !constraintAllowsMin($m[1])) {
    file_put_contents($composerFile, str_replace($m[0], '"ronu/rest-generic-class": "^2.7"', $composer));
    ok("composer.json: {$m[1]} → ^2.7");
} else {
    ok('composer.json: la restricción ya exige ' . MIN_VERSION . ' o superior.');
}
stepDone();

// 2. route files
foreach ($patches as $file => $p) {
    file_put_contents($file, $p['content']);
    ok('Marcadas como globales en ' . relative($path, $file) . ': ' . implode(', ', $p['names']));
}
stepDone();

// 3-5. data, with the new configuration injected (enforcement is not active yet for live traffic)
if ($now['config_cached']) {
    artisan($php, $path, ['config:clear'], []);
}
if (!$opt['skip_sync']) {
    artisan($php, $path, ['permissions:sync-from-routes', "--guard=$guard"], $proposed);
    ok('Permisos sincronizados.');
}
stepDone();
foreach ($grants as $g) {
    $args = ['roles:assign-permissions', $g['role'], '--by=name', "--guard=$guard", '--no-create'];
    if ($g['file'] === null) {
        artisan($php, $path, [...$args, "--prefix={$g['prefix']}"], $proposed);
        ok("{$g['role']} ← {$g['prefix']}*");
        continue;
    }
    // roles:assign-permissions resolves --from with base_path(): pass it relative to the project,
    // copying it inside the project first when it lives elsewhere (e.g. another drive).
    [$from, $copy] = projectRelativeFile($path, $g['file']);
    try {
        artisan($php, $path, [...$args, "--from=$from"], $proposed);
    } finally {
        $copy && @unlink($copy);
    }
    ok("{$g['role']} ← permisos de {$g['file']}");
}
stepDone();
artisan($php, $path, ['permission:cache-reset'], $proposed);
stepDone();

$check = runInspect($php, $path, $proposed);
if (!$check['db']['ok'] || ($check['db']['role_grants'] ?? 0) === 0) {
    if (!$opt['force']) {
        fail('Ningún rol tiene permisos: NO se activa la configuración (nadie podría entrar). Usa --grant, o --force.');
    }
    warn('Ningún rol tiene permisos y se activa igualmente (--force).');
}
stepDone();

// 6. activation
writeEnv("$path/.env.example", $proposed, true);
writeEnv("$path/.env", $proposed, $opt['force']);
if ($now['config_cached']) {
    artisan($php, $path, ['config:cache'], []);
}
artisan($php, $path, ['permission:cache-reset'], []);
stepDone();

// 7. verification against the real .env
title('Verificación');
$final = runInspect($php, $path, []);
$cfg = $final['cfg'];
$active = ($cfg['naming'] ?? null) === 'route_name' && ($cfg['on_unresolved'] ?? null) === 'deny'
    && filter_var($cfg['register_gate'] ?? false, FILTER_VALIDATE_BOOLEAN) && ($cfg['route_prefixes'] ?? null);
$active ? ok('Configuración activa.') : err('La configuración leída NO es la esperada: revisa .env y la caché de configuración.');
$finalProtected = array_filter($final['routes'], fn ($r) => $r['protected']);
$finalUnresolved = array_filter($finalProtected, fn ($r) => $r['resolved'] === null);
$finalUnresolved ? err(count($finalUnresolved) . ' rutas protegidas sin permiso resoluble.') : ok('Todas las rutas protegidas resuelven un permiso.');
$marked = array_column(array_filter($final['routes'], fn ($r) => $r['global']), 'name');
ok('Globales: ' . (implode(', ', $marked) ?: 'ninguna'));
ok("Permisos en BD: {$final['db']['permissions']}, asignaciones a roles: {$final['db']['role_grants']}");
stepDone();

title('Pendiente (manual, depende de cada proyecto)');
line('- Login por canal: emitir token solo si el usuario tiene algún permiso restringido "<canal>.*" (login, refresh, login social).');
line('- GET /permissions: devolver solo los permisos del canal actual (los globales existen en todos los canales).');
line('- Tests de regresión: un rol sin permisos recibe 403 en otro canal; el rol con permisos recibe 200.');
line('- Documentación del proyecto: configuración REST_AUTHZ_*, permission_global y el orden de despliegue.');
line('- Desplegar: este script ya ordena los pasos; en otros entornos, sincronizar y asignar ANTES de cambiar su .env.');
exit(0);

// ---------------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------------
function parseOptions(array $argv): array
{
    $o = ['path' => getcwd(), 'apply' => false, 'force' => false, 'skip_sync' => false, 'grant' => [],
        'globals' => explode(',', DEFAULT_GLOBALS), 'prefixes' => [], 'guard' => null, 'php' => PHP_BINARY];
    foreach (array_slice($argv, 1) as $a) {
        [$k, $v] = array_pad(explode('=', $a, 2), 2, null);
        match ($k) {
            '--path' => $o['path'] = $v,
            '--apply' => $o['apply'] = true,
            '--force' => $o['force'] = true,
            '--skip-sync' => $o['skip_sync'] = true,
            '--grant' => $o['grant'][] = checkGrant(parseGrant((string) $v) ?? fail("--grant=$v: usa Rol:prefijo o Rol:@archivo.json")),
            '--globals' => $o['globals'] = array_values(array_filter(array_map('trim', explode(',', (string) $v)))),
            '--prefixes' => $o['prefixes'] = array_values(array_filter(array_map('trim', explode(',', (string) $v)))),
            '--guard' => $o['guard'] = $v,
            '--php' => $o['php'] = $v,
            '-h', '--help' => usage(),
            default => fail("Opción desconocida: $a"),
        };
    }
    return $o;
}

/** A --grant file must exist and be a format roles:assign-permissions --from reads; stored as an absolute path. */
function checkGrant(array $grant): array
{
    if ($grant['file'] === null) {
        return $grant;
    }
    $file = realpath($grant['file']);
    if ($file === false || !is_file($file)) {
        fail("--grant={$grant['role']}:@{$grant['file']}: no existe el archivo.");
    }
    if (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['json', 'yml', 'yaml'], true)) {
        fail("--grant={$grant['role']}:@{$grant['file']}: usa un archivo .json, .yml o .yaml con una lista de permisos.");
    }
    return ['file' => str_replace('\\', '/', $file)] + $grant;
}

/**
 * [path relative to the project, temporary copy to delete or null]. A file outside the project is
 * copied into it, because the command resolves the path with base_path().
 */
function projectRelativeFile(string $project, string $file): array
{
    if (str_starts_with(strtolower($file), strtolower("$project/"))) {
        return [substr($file, strlen($project) + 1), null];
    }
    $copy = "$project/.channel-authorization-grant-" . bin2hex(random_bytes(4)) . '.' . pathinfo($file, PATHINFO_EXTENSION);
    if (!copy($file, $copy)) {
        fail("No se pudo copiar $file dentro del proyecto.");
    }
    return [basename($copy), $copy];
}

/** Track the --apply steps; the summary is printed when the script ends, whether it finished or aborted. */
function startProgress(array $steps): void
{
    $GLOBALS['__progress'] = ['steps' => $steps, 'done' => 0];
    register_shutdown_function(static function () {
        $p = $GLOBALS['__progress'];
        $aborted = $p['done'] < count($p['steps']);
        echo PHP_EOL, $aborted ? '== Pasos de --apply (interrumpido: continúa desde el marcado con ✘)' : '== Pasos de --apply', PHP_EOL;
        foreach (progressLines($p['steps'], $p['done'], $aborted) as $l) {
            echo $l, PHP_EOL;
        }
    });
}

function stepDone(): void
{
    $GLOBALS['__progress']['done']++;
}

function usage(): never
{
    $doc = file_get_contents(__FILE__);
    preg_match('/\/\*\*(.*?)\*\//s', $doc, $m);
    echo preg_replace('/^\s*\* ?/m', '', $m[1]), PHP_EOL;
    exit(0);
}

function runInspect(string $php, string $path, array $env): array
{
    $out = run([$php, __FILE__, '--__inspect', "--path=$path"], $path, $env);
    $data = json_decode($out, true);
    if (!is_array($data)) {
        fail("No se pudo inspeccionar el proyecto:\n" . $out);
    }
    return $data;
}

function artisan(string $php, string $path, array $args, array $env): string
{
    line('  $ php artisan ' . implode(' ', $args));
    return run(array_merge([$php, "$path/artisan"], $args, ['--no-interaction', '--no-ansi']), $path, $env);
}

function run(array $cmd, string $cwd, array $env): string
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, array_merge(getenv(), $env));
    if (!is_resource($proc)) {
        fail('No se pudo ejecutar: ' . implode(' ', $cmd));
    }
    $out = stream_get_contents($pipes[1]);
    $errOut = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        fail('Falló: ' . implode(' ', $cmd) . "\n" . $out . $errOut);
    }
    return $out;
}

function writeEnv(string $file, array $values, bool $overwrite): void
{
    $src = is_file($file) ? file_get_contents($file) : '';
    $added = [];
    foreach ($values as $k => $v) {
        if (preg_match("/^$k=(.*)$/m", $src, $m)) {
            if (trim($m[1]) !== $v) {
                if ($overwrite) {
                    $src = preg_replace("/^$k=.*$/m", "$k=$v", $src);
                } else {
                    warn(basename($file) . ": $k ya vale '{$m[1]}'; se conserva (usa --force para cambiarlo a '$v').");
                }
            }
        } else {
            $added[] = "$k=$v";
        }
    }
    if ($added) {
        $src = rtrim($src) . "\n\n# Channel-scoped authorization (ronu/rest-generic-class " . MIN_VERSION . "+)\n" . implode("\n", $added) . "\n";
    }
    file_put_contents($file, $src);
    ok(basename($file) . ' actualizado.');
}

/** Print a capped list: a header with the total, then at most $max examples. */
function listSome(array $items, callable $printer, string $header, callable $format, int $max = 10): void
{
    if (!$items) {
        return;
    }
    $printer($header . ': ' . count($items));
    foreach (array_slice(array_values($items), 0, $max) as $item) {
        line('      ' . $format($item));
    }
    if (count($items) > $max) {
        line('      ... y ' . (count($items) - $max) . ' más');
    }
}

function title(string $t): void { echo PHP_EOL, "== $t", PHP_EOL; }
function line(string $t): void { echo $t, PHP_EOL; }
function ok(string $t): void { echo "  [ok] $t", PHP_EOL; }
function warn(string $t): void { echo "  [aviso] $t", PHP_EOL; }
function err(string $t): void { echo "  [error] $t", PHP_EOL; }
function fail(string $t): never { fwrite(STDERR, PHP_EOL . "[abortado] $t" . PHP_EOL); exit(2); }
