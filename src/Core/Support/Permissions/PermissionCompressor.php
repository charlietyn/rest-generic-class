<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Support\Collection;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\PermissionCompressorContract;

final class PermissionCompressor implements PermissionCompressorContract
{
    public function compress(
        Collection $permissions,
        Collection $allSystemPerms,
        array $options = []
    ): PermissionCompressedResult {
        $opts = array_merge([
            'module_wildcard' => true,
            'table_wildcard' => true,
            'global_wildcard' => false,
            'include_expanded' => false,
        ], $options);

        $universe = $this->buildPermissionMap($allSystemPerms);
        $subject = $this->buildPermissionMap($permissions);
        $collapsed = $this->collapseToWildcards($subject, $universe, $opts);

        return new PermissionCompressedResult(
            $collapsed['wildcards'],
            $collapsed['individuals'],
            $collapsed['stats'],
            $opts['include_expanded'] ? $subject['all'] : null
        );
    }

    private function buildPermissionMap(Collection $permissions): array
    {
        $names = [];
        foreach ($permissions as $permission) {
            $name = $this->permissionName($permission);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return ['all' => $this->uniqueSorted($names)];
    }

    private function permissionName($permission): ?string
    {
        if (is_string($permission)) {
            $name = $permission;
        } elseif (is_array($permission)) {
            $name = $permission['name'] ?? null;
        } elseif (is_object($permission)) {
            $name = $permission->name ?? null;
        } else {
            $name = null;
        }

        $name = is_scalar($name) ? trim((string)$name) : '';

        return $name !== '' ? $name : null;
    }

    private function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * Hierarchical compression at any depth: walks the prefix tree of the universe (`canal`, `canal.modulo`,
     * `canal.modulo.entidad`, `….entidad.relacion`, …) top-down and emits `prefix.*` for the shortest prefix
     * whose actions the subject holds completely. Depth 1 honours `module_wildcard`, deeper levels
     * `table_wildcard`. Wildcards are ordered by depth, then name; individual names alphabetically.
     */
    private function collapseToWildcards(array $subject, array $universe, array $opts): array
    {
        if ($opts['global_wildcard'] && $universe['all'] !== [] && $this->containsAll($subject['all'], $universe['all'])) {
            return [
                'wildcards' => ['*'],
                'individuals' => [],
                'stats' => $this->stats(count($subject['all']), 1),
            ];
        }

        $held = array_flip($subject['all']);
        $under = [];
        $children = ['' => []];
        foreach ($universe['all'] as $name) {
            $segments = explode('.', $name);
            for ($depth = 1; $depth < count($segments); $depth++) {
                $prefix = implode('.', array_slice($segments, 0, $depth));
                $parent = $depth === 1 ? '' : implode('.', array_slice($segments, 0, $depth - 1));
                $under[$prefix][] = $name;
                $children[$parent][$prefix] = true;
            }
        }

        $wildcards = [];
        $covered = [];
        $visit = function (string $prefix, int $depth) use (&$visit, &$wildcards, &$covered, $under, $children, $held, $opts): void {
            $allowed = $depth === 1 ? $opts['module_wildcard'] : $opts['table_wildcard'];
            if ($allowed && $this->holdsAll($held, $under[$prefix])) {
                $wildcards[] = [$depth, "{$prefix}.*"];
                foreach ($under[$prefix] as $name) {
                    $covered[$name] = true;
                }
                return;
            }
            foreach (array_keys($children[$prefix] ?? []) as $child) {
                $visit($child, $depth + 1);
            }
        };
        foreach (array_keys($children['']) as $prefix) {
            $visit($prefix, 1);
        }

        usort($wildcards, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $wildcards = array_column($wildcards, 1);
        $individuals = array_values(array_filter($subject['all'], static fn (string $name) => !isset($covered[$name])));

        return [
            'wildcards' => $wildcards,
            'individuals' => $individuals,
            'stats' => $this->stats(count($subject['all']), count($wildcards) + count($individuals)),
        ];
    }

    private function holdsAll(array $held, array $names): bool
    {
        foreach ($names as $name) {
            if (!isset($held[$name])) {
                return false;
            }
        }
        return true;
    }

    private function containsAll(array $subjectValues, array $universeValues): bool
    {
        return array_diff($universeValues, $subjectValues) === [];
    }

    private function stats(int $originalCount, int $compressedCount): array
    {
        return [
            'original_count' => $originalCount,
            'compressed_count' => $compressedCount,
            'compression_ratio' => $originalCount > 0 ? round($originalCount / max($compressedCount, 1), 2) : 1.0,
        ];
    }
}
