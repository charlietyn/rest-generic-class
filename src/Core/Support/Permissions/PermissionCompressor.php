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
        $map = [
            'by_module_table' => [],
            'by_module' => [],
            'all' => [],
        ];

        foreach ($permissions as $permission) {
            $name = $this->permissionName($permission);

            if ($name === null) {
                continue;
            }

            $map['all'][] = $name;
            $parts = explode('.', $name);

            if (count($parts) >= 3) {
                $module = $parts[0];
                $table = $parts[1];
                $action = implode('.', array_slice($parts, 2));

                $map['by_module_table'][$module][$table][] = $action;
                $map['by_module'][$module][] = $name;
                continue;
            }

            if (count($parts) === 2) {
                $map['by_module'][$parts[0]][] = $name;
                continue;
            }

            $map['by_module']['__root'][] = $name;
        }

        return $this->sortMap($map);
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

    private function sortMap(array $map): array
    {
        $map['all'] = $this->uniqueSorted($map['all']);

        foreach ($map['by_module'] as $module => $permissionNames) {
            $map['by_module'][$module] = $this->uniqueSorted($permissionNames);
        }
        ksort($map['by_module'], SORT_STRING);

        foreach ($map['by_module_table'] as $module => $tables) {
            foreach ($tables as $table => $actions) {
                $map['by_module_table'][$module][$table] = $this->uniqueSorted($actions);
            }
            ksort($map['by_module_table'][$module], SORT_STRING);
        }
        ksort($map['by_module_table'], SORT_STRING);

        return $map;
    }

    private function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    private function collapseToWildcards(array $subject, array $universe, array $opts): array
    {
        $wildcards = [];
        $individuals = [];
        $covered = [];

        if ($opts['global_wildcard'] && $universe['all'] !== [] && $this->containsAll($subject['all'], $universe['all'])) {
            return [
                'wildcards' => ['*'],
                'individuals' => [],
                'stats' => $this->stats(count($subject['all']), 1),
            ];
        }

        if ($opts['module_wildcard']) {
            foreach ($universe['by_module'] as $module => $universePerms) {
                if ($module === '__root') {
                    continue;
                }

                $subjectPerms = $subject['by_module'][$module] ?? [];

                if ($subjectPerms !== [] && $this->containsAll($subjectPerms, $universePerms)) {
                    $wildcards[] = "{$module}.*";
                    $covered = array_merge($covered, $universePerms);
                }
            }
        }

        if ($opts['table_wildcard']) {
            foreach ($universe['by_module_table'] as $module => $tables) {
                if (in_array("{$module}.*", $wildcards, true)) {
                    continue;
                }

                foreach ($tables as $table => $universeActions) {
                    $subjectActions = $subject['by_module_table'][$module][$table] ?? [];

                    if ($subjectActions !== [] && $this->containsAll($subjectActions, $universeActions)) {
                        $wildcards[] = "{$module}.{$table}.*";

                        foreach ($universeActions as $action) {
                            $covered[] = "{$module}.{$table}.{$action}";
                        }
                    }
                }
            }
        }

        $covered = array_values(array_unique($covered));

        foreach ($subject['all'] as $permissionName) {
            if (!in_array($permissionName, $covered, true)) {
                $individuals[] = $permissionName;
            }
        }

        return [
            'wildcards' => $wildcards,
            'individuals' => $individuals,
            'stats' => $this->stats(count($subject['all']), count($wildcards) + count($individuals)),
        ];
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
