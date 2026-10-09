<?php
declare(strict_types=1);

/**
 * Pure helpers of enable-channel-authorization.php: no output, no process or project boot,
 * so they can be unit tested. Loaded with require_once by the script.
 */

/**
 * Wrap each matching single-statement route declaration in its own group:
 *   Route::get(...)->name('auth.permissions');
 * → Route::group(['permission_global' => true], fn () => Route::get(...)->name('auth.permissions'));
 * An arrow function keeps outer variables (e.g. $version) in scope, and the group isolates the mark to
 * that one route, so siblings in the same group are not made global by accident.
 *
 * $targets are inspected routes (name, global); $files the route files to scan. Each patch reports the
 * full route names it marks, resolved when the declaration is patched (see resolveFullNames()).
 *
 * @return array<string, array{content: string, diff: list<array{0: string, 1: string}>, names: list<string>}>
 */
function planRoutePatches(array $files, array $targets, array $prefixes, array $patterns): array
{
    $pending = array_values(array_column(array_filter($targets, fn ($r) => !$r['global'] && $r['name']), 'name'));
    if (!$pending) {
        return [];
    }
    $claimed = [];
    $patches = [];
    foreach ($files as $file) {
        $src = file_get_contents($file);
        $diff = [];
        $names = [];
        $offset = 0;
        while (preg_match('/->name\(\s*([\'"])([^\'"]+)\1\s*\)/', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $local = $m[2][0];
            $offset = $m[0][1] + strlen($m[0][0]);
            $full = resolveFullNames($local, $file, $src, array_values(array_diff($pending, $claimed)), $prefixes, $patterns);
            if (!$full) {
                continue;
            }
            $start = statementStart($src, $m[0][1]);
            $end = strpos($src, ';', $offset);
            if ($start === null || $end === false) {
                continue;
            }
            $stmt = substr($src, $start, $end - $start);
            if (!str_starts_with($stmt, 'Route::') || str_contains(substr($src, max(0, $start - 60), 60), 'permission_global')) {
                continue;
            }
            $new = "Route::group(['permission_global' => true], fn () => $stmt)";
            $src = substr($src, 0, $start) . $new . substr($src, $end);
            $offset = $start + strlen($new);
            $diff[] = [preg_replace('/\s+/', ' ', $stmt) . ';', preg_replace('/\s+/', ' ', $new) . ';'];
            array_push($names, ...$full);
            array_push($claimed, ...$full);
        }
        if ($diff) {
            $patches[$file] = ['content' => $src, 'diff' => $diff, 'names' => array_values(array_unique($names))];
        }
    }
    return $patches;
}

/**
 * Full route names that a `->name($local)` declaration in $file produces, among the pending ones.
 * A local name such as `auth.permissions` matches one candidate per channel (`admin.auth.permissions`,
 * `client.auth.permissions`, ...), so the channel is taken from the file: its basename
 * (routes/admin.php) or, failing that, the only channel prefix the file mentions (`'as' => 'admin.'`).
 * When the file cannot be tied to one channel, every candidate is returned: the declaration really
 * serves all of them (e.g. one file registered under several prefixes).
 *
 * @return list<string>
 */
function resolveFullNames(string $local, string $file, string $src, array $pending, array $prefixes, array $patterns): array
{
    if (in_array($local, $pending, true) && matchesAny($local, $patterns)) {
        return [$local];
    }
    $candidates = [];
    foreach ($prefixes as $p) {
        $full = "$p.$local";
        if (in_array($full, $pending, true) && matchesAny($full, $patterns)) {
            $candidates[$p] = $full;
        }
    }
    if (count($candidates) < 2) {
        return array_values($candidates);
    }
    $base = basename($file, '.php');
    if (isset($candidates[$base])) {
        return [$candidates[$base]];
    }
    $mentioned = array_filter(array_keys($candidates), fn ($p) => preg_match('/([\'"])' . preg_quote($p, '/') . '\.\1/', $src) === 1);
    if (count($mentioned) === 1) {
        return [$candidates[reset($mentioned)]];
    }
    return array_values($candidates);
}

/** Start of the PHP statement that contains $pos: after the previous ; { } or <?php, skipping blanks and comments. */
function statementStart(string $src, int $pos): ?int
{
    $before = substr($src, 0, $pos);
    $open = strpos($before, '<?php');
    $i = max(
        strrpos($before, ';') ?: 0,
        strrpos($before, '{') ?: 0,
        strrpos($before, '}') ?: 0,
        $open === false ? 0 : $open + 4
    );
    $i++;
    while ($i < $pos) {
        if (ctype_space($src[$i])) {
            $i++;
        } elseif (substr($src, $i, 2) === '//') {
            $i = strpos($src, "\n", $i) ?: $pos;
        } elseif (substr($src, $i, 2) === '/*') {
            $i = (strpos($src, '*/', $i) ?: $pos) + 2;
        } else {
            return $i;
        }
    }
    return null;
}

function constraintAllowsMin(string $constraint, string $min = '2.7'): bool
{
    // Only the lower bound matters here: ^2.7, ~2.7, >=2.7, 2.7.* ... (or anything newer).
    return (bool) preg_match('/(\d+)\.(\d+)/', $constraint, $m) && version_compare("{$m[1]}.{$m[2]}", $min, '>=');
}

function matchesAny(string $name, array $patterns): bool
{
    foreach ($patterns as $p) {
        if (preg_match('/^' . str_replace('\*', '.*', preg_quote($p, '/')) . '$/', $name)) {
            return true;
        }
    }
    return false;
}

function startsWithAny(string $s, array $prefixes): bool
{
    foreach ($prefixes as $p) {
        if (str_starts_with($s, $p)) {
            return true;
        }
    }
    return false;
}

function relative(string $base, string $file): string
{
    return ltrim(str_replace($base, '', str_replace('\\', '/', $file)), '/');
}

/**
 * Parse one --grant value: `Role:prefix` grants every permission starting with the prefix,
 * `Role:@file.json` (or .yml/.yaml) an explicit flat list. Returns null when the value is malformed.
 *
 * @return array{role: string, prefix: ?string, file: ?string}|null
 */
function parseGrant(string $value): ?array
{
    if (!str_contains($value, ':')) {
        return null;
    }
    [$role, $spec] = explode(':', $value, 2);
    if (trim($role) === '' || trim($spec) === '' || $spec === '@') {
        return null;
    }
    return str_starts_with($spec, '@')
        ? ['role' => $role, 'prefix' => null, 'file' => substr($spec, 1)]
        : ['role' => $role, 'prefix' => $spec, 'file' => null];
}

/**
 * One line per --apply step: ✔ done, ✘ the step that was running when the script stopped, · not reached.
 *
 * @param list<string> $steps
 * @return list<string>
 */
function progressLines(array $steps, int $done, bool $aborted): array
{
    $lines = [];
    foreach ($steps as $i => $step) {
        $mark = $i < $done ? '✔' : ($aborted && $i === $done ? '✘' : '·');
        $lines[] = "  $mark $step";
    }
    return $lines;
}
