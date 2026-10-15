<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resolves the target set of permissions to ADD/SYNC/REVOKE from the many
 * supported sources: explicit names, a name prefix, a file, modules, entities,
 * or the "all permissions of a guard" default.
 *
 * Extracted from HasPermissionsService (SRP): the trait now only orchestrates
 * mode/cache/summary and delegates target resolution here.
 */
final class TargetPermissionResolver
{
    public function __construct(
        private ?PermissionListNormalizer $normalizer = null
    ) {
        $this->normalizer ??= new PermissionListNormalizer();
    }

    /**
     * @return array{0:Collection,1:array,2:bool} [resolvedPerms, createdNames, usedDefaultAll]
     */
    public function resolve(
        string  $guard,
        ?array  $perms,
        ?string $prefix,
        ?string $from,
        ?array  $modules,
        ?array  $entities,
    ): array {
        $names = collect();
        $permissionClass = app(config('permission.models.permission'));
        $catalog = null;
        $catalogNames = function () use (&$catalog, $permissionClass, $guard): array {
            return $catalog ??= $permissionClass::query()->where('guard_name', $guard)->pluck('name')->all();
        };

        if (!empty($perms)) {
            $names = $names->merge($this->normalizer->normalize($perms));
        }

        if ($prefix) {
            // Segment-bounded: admin.crm.clients does not match admin.crm.clients-notes.
            $names = $names->merge(ActionPattern::expandPrefix($prefix, $catalogNames()));
        }

        if ($from) {
            $names = $names->merge($this->loadPermsFromFile($from));
        }

        // Generic notation (admin.crm.clients.*) in --perms and role files, expanded against the catalog.
        $names = $names->flatMap(fn ($name) => ActionPattern::isPattern((string) $name)
            ? ActionPattern::expand((string) $name, $catalogNames())
            : [$name]);

        if (!empty($modules)) {
            $byModule = $this->loadPermsByModule($guard, $this->normalizer->normalize($modules), $permissionClass);
            $names = $names->merge($byModule->pluck('name'));
        }

        if (!empty($entities)) {
            $byEntity = $this->loadPermsByEntity($guard, $this->normalizer->normalize($entities), $permissionClass);
            $names = $names->merge($byEntity->pluck('name'));
        }

        $names = $names->map(fn($n) => trim((string)$n))->filter()->unique()->values();

        $usedDefaultAll = false;
        $createdNames = [];
        $resolved = collect();

        // "All of the guard" only when no source was given: a pattern that matches nothing grants nothing.
        if ($names->isEmpty() && empty($perms) && !$prefix && !$from && empty($modules) && empty($entities)) {
            $all = $permissionClass::query()->where('guard_name', $guard)->get();
            return [$all, $createdNames, true];
        }

        // One query per batch instead of one per name; keep the requested order.
        $found = collect();
        foreach ($names->chunk(500) as $chunk) {
            $found = $found->merge(
                $permissionClass::query()->where('guard_name', $guard)->whereIn('name', $chunk->values()->all())->get()
            );
        }
        $byName = $found->keyBy('name');
        foreach ($names as $name) {
            if ($byName->has($name)) {
                $resolved->push($byName->get($name));
            }
        }

        return [$resolved->unique('id')->values(), $createdNames, $usedDefaultAll];
    }

    public function loadPermsByModule(string $guard, array $modules, $permissionClass): Collection
    {
        return $permissionClass::query()
            ->where('guard_name', $guard)
            ->whereIn('module', $modules)
            ->get();
    }

    public function loadPermsByEntity(string $guard, array $entities, $permissionClass): Collection
    {
        return $permissionClass::query()
            ->where('guard_name', $guard)
            ->where(function (Builder $outer) use ($entities) {
                foreach ($entities as $raw) {
                    $raw = trim((string)$raw);
                    $module = null;
                    $entity = $raw;

                    if (Str::contains($raw, '.')) {
                        $module = Str::before($raw, '.');
                        $entity = Str::after($raw, '.');
                    }

                    // LOWER() = LOWER() instead of PostgreSQL-only ilike: portable to MySQL, SQLite and SQL Server.
                    $outer->orWhere(function (Builder $sub) use ($entity, $module) {
                        $sub->whereRaw('LOWER(model) = LOWER(?)', [$entity]);
                        if (!empty($module)) {
                            $sub->whereRaw('LOWER(module) = LOWER(?)', [$module]);
                        }
                    });
                }
            })
            ->get();
    }

    public function loadPermsFromFile(string $from): array
    {
        $filePath = base_path($from);
        if (!is_file($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contents = file_get_contents($filePath);

        if ($ext === 'json') {
            $list = json_decode($contents, true);
        } elseif (in_array($ext, ['yml', 'yaml'])) {
            if (!function_exists('yaml_parse')) {
                throw new \RuntimeException('YAML extension not available (yaml_parse missing). Use JSON or enable ext/yaml.');
            }
            $list = yaml_parse($contents);
        } else {
            throw new \RuntimeException('Unsupported file extension. Use .json or .yml/.yaml');
        }

        if (!is_array($list)) {
            throw new \RuntimeException('The file does not contain a valid flat list.');
        }

        return $this->normalizer->normalize($list);
    }
}
