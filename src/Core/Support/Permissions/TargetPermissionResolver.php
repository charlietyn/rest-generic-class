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

        if (!empty($perms)) {
            $names = $names->merge($this->normalizer->normalize($perms));
        }

        if ($prefix) {
            $prefixed = $permissionClass::query()
                ->where('guard_name', $guard)
                ->where('name', 'like', $prefix . '%')
                ->pluck('name');
            $names = $names->merge($prefixed);
        }

        if ($from) {
            $names = $names->merge($this->loadPermsFromFile($from));
        }

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

        if ($names->isEmpty() && !$prefix && !$from && empty($modules) && empty($entities)) {
            $all = $permissionClass::query()->where('guard_name', $guard)->get();
            return [$all, $createdNames, true];
        }

        foreach ($names as $name) {
            $perm = $permissionClass::where('name', $name)->where('guard_name', $guard)->first();
            if ($perm) {
                $resolved->push($perm);
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

                    $outer->orWhere(function (Builder $sub) use ($entity, $module) {
                        $sub->where('model', 'ilike', $entity);
                        if (!empty($module)) {
                            $sub->where('module', 'ilike', $module);
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
