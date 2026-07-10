<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HierarchyCoordinator
{
    private const LIST_DEFAULTS = [
        'children_key' => 'children',
        'max_depth' => null,
        'filter_mode' => 'match_only',
        'include_empty_children' => true,
    ];

    private const LIST_FILTER_MODES = [
        'match_only',
        'with_ancestors',
        'with_descendants',
        'full_branch',
        'root_filter',
    ];

    private const SHOW_DEFAULTS = [
        'children_key' => 'children',
        'max_depth' => null,
        'mode' => 'with_descendants',
        'include_empty_children' => true,
    ];

    private const SHOW_MODES = [
        'node_only',
        'with_descendants',
        'with_ancestors',
        'full_branch',
    ];

    private Closure $processQuery;
    private Closure $applyRelations;
    private Closure $fallbackList;
    private Closure $fallbackShow;

    public function __construct(
        private Model $model,
        callable $processQuery,
        callable $applyRelations,
        callable $fallbackList,
        callable $fallbackShow
    ) {
        $this->processQuery = Closure::fromCallable($processQuery);
        $this->applyRelations = Closure::fromCallable($applyRelations);
        $this->fallbackList = Closure::fromCallable($fallbackList);
        $this->fallbackShow = Closure::fromCallable($fallbackShow);
    }

    public function list(array $params, bool $toJson = true): mixed
    {
        $this->assertSupportsHierarchy('listing');

        $hierarchyConfig = $this->normalizeListParams($params['hierarchy'] ?? null);

        if ($hierarchyConfig === null) {
            unset($params['hierarchy']);
            return ($this->fallbackList)($params, $toJson);
        }

        $hierarchyFieldId = $this->model->getHierarchyFieldId();
        $primaryKey = $this->model->getKeyName();
        $childrenKey = $hierarchyConfig['children_key'];
        $maxDepth = $hierarchyConfig['max_depth'];
        $filterMode = $hierarchyConfig['filter_mode'];
        $includeEmptyChildren = $hierarchyConfig['include_empty_children'];

        $query = $this->model->query();
        $query = ($this->processQuery)($params, $query);
        $allRecords = $query->get();

        $finalRecords = $this->applyListFilterMode(
            $allRecords,
            $filterMode,
            $hierarchyFieldId,
            $primaryKey
        );

        $tree = $this->buildTree(
            $finalRecords,
            $hierarchyFieldId,
            $primaryKey,
            $childrenKey,
            $maxDepth,
            $includeEmptyChildren
        );

        if (isset($params['pagination'])) {
            return $this->paginateRoots($tree, $params['pagination']);
        }

        return $toJson ? ['data' => $tree] : $tree;
    }

    public function show(array $params, mixed $id): array
    {
        $this->assertSupportsHierarchy('show');

        $hierarchyConfig = $this->normalizeShowParams($params['hierarchy'] ?? null);

        if ($hierarchyConfig === null) {
            unset($params['hierarchy']);
            $result = ($this->fallbackShow)($params, $id);
            return is_object($result) && method_exists($result, 'toArray')
                ? $result->toArray()
                : (array)$result;
        }

        $hierarchyFieldId = $this->model->getHierarchyFieldId();
        $primaryKey = $this->model->getKeyName();
        $childrenKey = $hierarchyConfig['children_key'];
        $maxDepth = $hierarchyConfig['max_depth'];
        $mode = $hierarchyConfig['mode'];
        $includeEmptyChildren = $hierarchyConfig['include_empty_children'];

        $nested = $params['_nested'] ?? false;
        $query = $this->model->query();

        if (isset($params['relations'])) {
            $query = ($this->applyRelations)(
                $query,
                $params['relations'],
                $nested ? ($params['oper'] ?? null) : null
            );
        }

        if (isset($params['select'])) {
            $query = $query->select($params['select']);
        }

        $record = $query->findOrFail($id);

        return $this->buildShowTree(
            $record,
            $mode,
            $hierarchyFieldId,
            $primaryKey,
            $childrenKey,
            $maxDepth,
            $includeEmptyChildren,
            $params
        );
    }

    private function assertSupportsHierarchy(string $operation): void
    {
        if (!method_exists($this->model, 'hasHierarchyField') || !$this->model->hasHierarchyField()) {
            throw new HttpException(
                400,
                "Model " . get_class($this->model) . " does not support hierarchical {$operation}. " .
                "Define const HIERARCHY_FIELD_ID to enable this feature."
            );
        }
    }

    private function normalizeListParams(mixed $hierarchy): ?array
    {
        if ($hierarchy === null || $hierarchy === false) {
            return null;
        }

        if ($hierarchy === true || $hierarchy === 'true' || $hierarchy === '1') {
            return self::LIST_DEFAULTS;
        }

        if (is_string($hierarchy)) {
            $decoded = json_decode($hierarchy, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            $hierarchy = $decoded;
        }

        if (!is_array($hierarchy)) {
            return null;
        }

        if (isset($hierarchy['enabled']) && !$hierarchy['enabled']) {
            return null;
        }

        $config = array_merge(self::LIST_DEFAULTS, $hierarchy);

        if (!in_array($config['filter_mode'], self::LIST_FILTER_MODES, true)) {
            throw new HttpException(
                400,
                "Invalid hierarchy filter_mode '{$config['filter_mode']}'. Valid modes: " .
                implode(', ', self::LIST_FILTER_MODES)
            );
        }

        $this->assertValidMaxDepth($config['max_depth']);

        return $config;
    }

    private function normalizeShowParams(mixed $hierarchy): ?array
    {
        if ($hierarchy === null || $hierarchy === false) {
            return null;
        }

        if ($hierarchy === true || $hierarchy === 'true' || $hierarchy === '1') {
            return self::SHOW_DEFAULTS;
        }

        if (is_string($hierarchy)) {
            $decoded = json_decode($hierarchy, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            $hierarchy = $decoded;
        }

        if (!is_array($hierarchy)) {
            return null;
        }

        if (isset($hierarchy['enabled']) && !$hierarchy['enabled']) {
            return null;
        }

        $config = array_merge(self::SHOW_DEFAULTS, $hierarchy);

        if (!in_array($config['mode'], self::SHOW_MODES, true)) {
            throw new HttpException(
                400,
                "Invalid hierarchy mode '{$config['mode']}'. Valid modes for show: " .
                implode(', ', self::SHOW_MODES)
            );
        }

        $this->assertValidMaxDepth($config['max_depth']);

        return $config;
    }

    private function assertValidMaxDepth(mixed $maxDepth): void
    {
        if ($maxDepth !== null && (!is_int($maxDepth) || $maxDepth < 1)) {
            throw new HttpException(400, "Hierarchy max_depth must be a positive integer or null.");
        }
    }

    private function buildShowTree(
        Model $record,
        string $mode,
        string $hierarchyFieldId,
        string $primaryKey,
        string $childrenKey,
        ?int $maxDepth,
        bool $includeEmptyChildren,
        array $params
    ): array {
        $recordId = $record->{$primaryKey};

        switch ($mode) {
            case 'node_only':
                $result = $record->toArray();
                if ($includeEmptyChildren) {
                    $result[$childrenKey] = [];
                }
                return $result;

            case 'with_descendants':
                $allRecords = $this->loadDescendants(
                    collect([$record]),
                    $hierarchyFieldId,
                    $primaryKey,
                    $params
                );

                $tree = $this->buildTree(
                    $allRecords,
                    $hierarchyFieldId,
                    $primaryKey,
                    $childrenKey,
                    $maxDepth,
                    $includeEmptyChildren
                );

                return $this->findNodeInTree($tree, $recordId, $primaryKey, $childrenKey)
                    ?? $record->toArray();

            case 'with_ancestors':
                $allRecords = $this->loadAncestors(
                    collect([$record]),
                    $hierarchyFieldId,
                    $primaryKey,
                    $params
                );

                $tree = $this->buildTree(
                    $allRecords,
                    $hierarchyFieldId,
                    $primaryKey,
                    $childrenKey,
                    $maxDepth,
                    $includeEmptyChildren
                );

                return $tree[0] ?? $record->toArray();

            case 'full_branch':
                $withAncestors = $this->loadAncestors(
                    collect([$record]),
                    $hierarchyFieldId,
                    $primaryKey,
                    $params
                );

                $allRecords = $this->loadDescendants(
                    $withAncestors,
                    $hierarchyFieldId,
                    $primaryKey,
                    $params
                );

                $tree = $this->buildTree(
                    $allRecords,
                    $hierarchyFieldId,
                    $primaryKey,
                    $childrenKey,
                    $maxDepth,
                    $includeEmptyChildren
                );

                return $tree[0] ?? $record->toArray();

            default:
                return $record->toArray();
        }
    }

    private function loadDescendants(
        Collection $records,
        string $hierarchyFieldId,
        string $primaryKey,
        array $params
    ): Collection {
        $existingIds = $records->pluck($primaryKey)->toArray();
        $allDescendants = collect();
        $currentIds = $existingIds;

        while (!empty($currentIds)) {
            $query = $this->model->query()->whereIn($hierarchyFieldId, $currentIds);

            if (isset($params['relations'])) {
                $query = ($this->applyRelations)($query, $params['relations'], null);
            }

            if (isset($params['select'])) {
                $query = $query->select($params['select']);
            }

            $children = $query->get();

            if ($children->isEmpty()) {
                break;
            }

            $newChildren = $children->filter(function ($child) use ($existingIds, $allDescendants, $primaryKey) {
                $id = $child->{$primaryKey};
                return !in_array($id, $existingIds) && !$allDescendants->contains($primaryKey, $id);
            });

            if ($newChildren->isEmpty()) {
                break;
            }

            $allDescendants = $allDescendants->merge($newChildren);
            $currentIds = $newChildren->pluck($primaryKey)->toArray();
        }

        return $records->merge($allDescendants);
    }

    private function loadAncestors(
        Collection $records,
        string $hierarchyFieldId,
        string $primaryKey,
        array $params
    ): Collection {
        $existingIds = $records->pluck($primaryKey)->toArray();
        $ancestorIds = [];

        foreach ($records as $record) {
            $parentId = $record->{$hierarchyFieldId};
            while ($parentId !== null && !in_array($parentId, $existingIds) && !in_array($parentId, $ancestorIds)) {
                $ancestorIds[] = $parentId;
                $parent = $this->model->query()
                    ->select([$primaryKey, $hierarchyFieldId])
                    ->find($parentId);
                $parentId = $parent ? $parent->{$hierarchyFieldId} : null;
            }
        }

        if (!empty($ancestorIds)) {
            $query = $this->model->query()->whereIn($primaryKey, $ancestorIds);

            if (isset($params['relations'])) {
                $query = ($this->applyRelations)($query, $params['relations'], null);
            }

            if (isset($params['select'])) {
                $query = $query->select($params['select']);
            }

            $records = $records->merge($query->get())->unique($primaryKey);
        }

        return $records;
    }

    private function findNodeInTree(array $tree, mixed $nodeId, string $primaryKey, string $childrenKey): ?array
    {
        foreach ($tree as $node) {
            if (($node[$primaryKey] ?? null) == $nodeId) {
                return $node;
            }

            if (!empty($node[$childrenKey])) {
                $found = $this->findNodeInTree($node[$childrenKey], $nodeId, $primaryKey, $childrenKey);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function applyListFilterMode(
        Collection $matchedRecords,
        string $filterMode,
        string $hierarchyFieldId,
        string $primaryKey
    ): Collection {
        if ($matchedRecords->isEmpty()) {
            return $matchedRecords;
        }

        switch ($filterMode) {
            case 'match_only':
                return $matchedRecords;

            case 'with_ancestors':
                return $this->addAncestorsToCollection($matchedRecords, $hierarchyFieldId, $primaryKey);

            case 'with_descendants':
                return $this->addDescendantsToCollection($matchedRecords, $hierarchyFieldId, $primaryKey);

            case 'full_branch':
                $withAncestors = $this->addAncestorsToCollection($matchedRecords, $hierarchyFieldId, $primaryKey);
                return $this->addDescendantsToCollection($withAncestors, $hierarchyFieldId, $primaryKey);

            case 'root_filter':
                $rootRecords = $matchedRecords->filter(fn($record) => $record->{$hierarchyFieldId} === null);
                return $this->addDescendantsToCollection($rootRecords, $hierarchyFieldId, $primaryKey);

            default:
                return $matchedRecords;
        }
    }

    private function addAncestorsToCollection(
        Collection $records,
        string $hierarchyFieldId,
        string $primaryKey
    ): Collection {
        $existingIds = $records->pluck($primaryKey)->toArray();
        $ancestorIds = [];

        foreach ($records as $record) {
            $parentId = $record->{$hierarchyFieldId};
            while ($parentId !== null && !in_array($parentId, $existingIds) && !in_array($parentId, $ancestorIds)) {
                $ancestorIds[] = $parentId;
                $parent = $this->model->query()->find($parentId);
                $parentId = $parent ? $parent->{$hierarchyFieldId} : null;
            }
        }

        if (!empty($ancestorIds)) {
            $ancestors = $this->model->query()
                ->whereIn($primaryKey, $ancestorIds)
                ->get();
            $records = $records->merge($ancestors)->unique($primaryKey);
        }

        return $records;
    }

    private function addDescendantsToCollection(
        Collection $records,
        string $hierarchyFieldId,
        string $primaryKey
    ): Collection {
        $existingIds = $records->pluck($primaryKey)->toArray();
        $allDescendantIds = [];
        $queue = $existingIds;

        while (!empty($queue)) {
            $childIds = $this->model->query()
                ->whereIn($hierarchyFieldId, $queue)
                ->pluck($primaryKey)
                ->toArray();

            $newIds = array_diff($childIds, $existingIds, $allDescendantIds);
            if (empty($newIds)) {
                break;
            }

            $allDescendantIds = array_merge($allDescendantIds, $newIds);
            $queue = $newIds;
        }

        if (!empty($allDescendantIds)) {
            $descendants = $this->model->query()
                ->whereIn($primaryKey, $allDescendantIds)
                ->get();
            $records = $records->merge($descendants)->unique($primaryKey);
        }

        return $records;
    }

    public function buildTree(
        Collection $records,
        string $hierarchyFieldId,
        string $primaryKey,
        string $childrenKey = 'children',
        ?int $maxDepth = null,
        bool $includeEmptyChildren = true
    ): array {
        if ($records->isEmpty()) {
            return [];
        }

        $recordsById = [];
        foreach ($records as $record) {
            $id = $record->{$primaryKey};
            $recordsById[$id] = $record->toArray();
            if ($includeEmptyChildren) {
                $recordsById[$id][$childrenKey] = [];
            }
        }

        $roots = [];

        foreach ($recordsById as &$record) {
            $parentId = $record[$hierarchyFieldId] ?? null;

            if ($parentId === null || !isset($recordsById[$parentId])) {
                $roots[] = &$record;
                continue;
            }

            if (!isset($recordsById[$parentId][$childrenKey])) {
                $recordsById[$parentId][$childrenKey] = [];
            }

            $recordsById[$parentId][$childrenKey][] = &$record;
        }
        unset($record);

        if ($maxDepth !== null) {
            $roots = $this->limitDepth($roots, $childrenKey, $maxDepth);
        }

        if (!$includeEmptyChildren) {
            $roots = $this->removeEmptyChildren($roots, $childrenKey);
        }

        return $roots;
    }

    private function limitDepth(array $nodes, string $childrenKey, int $maxDepth, int $currentDepth = 0): array
    {
        if ($currentDepth >= $maxDepth) {
            foreach ($nodes as &$node) {
                $node[$childrenKey] = [];
            }

            return $nodes;
        }

        foreach ($nodes as &$node) {
            if (!empty($node[$childrenKey])) {
                $node[$childrenKey] = $this->limitDepth(
                    $node[$childrenKey],
                    $childrenKey,
                    $maxDepth,
                    $currentDepth + 1
                );
            }
        }

        return $nodes;
    }

    private function removeEmptyChildren(array $nodes, string $childrenKey): array
    {
        foreach ($nodes as &$node) {
            if (!isset($node[$childrenKey])) {
                continue;
            }

            if (empty($node[$childrenKey])) {
                unset($node[$childrenKey]);
                continue;
            }

            $node[$childrenKey] = $this->removeEmptyChildren($node[$childrenKey], $childrenKey);
        }

        return $nodes;
    }

    private function paginateRoots(array $tree, mixed $pagination): array
    {
        if (is_string($pagination)) {
            $pagination = json_decode($pagination, true);
        }

        $pagination = is_array($pagination) ? $pagination : [];
        $totalRoots = count($tree);

        if (isset($pagination['infinity']) && $pagination['infinity'] === true) {
            $pageSize = $pagination['pageSize'] ?? $pagination['pagesize'] ?? $this->model->getPerPage();
            $cursor = $pagination['cursor'] ?? null;

            $startIndex = 0;
            if ($cursor !== null) {
                $decodedCursor = json_decode(base64_decode($cursor), true);
                $startIndex = is_array($decodedCursor) ? ($decodedCursor['index'] ?? 0) : 0;
            }

            $pagedRoots = array_slice($tree, $startIndex, $pageSize);
            $nextIndex = $startIndex + $pageSize;
            $hasMore = $nextIndex < $totalRoots;

            return [
                'data' => $pagedRoots,
                'next_cursor' => $hasMore ? base64_encode(json_encode(['index' => $nextIndex])) : null,
                'has_more' => $hasMore,
            ];
        }

        $page = $pagination['page'] ?? 1;
        $pageSize = $pagination['pageSize'] ?? $pagination['pagesize'] ?? $this->model->getPerPage();
        $offset = ($page - 1) * $pageSize;
        $pagedRoots = array_slice($tree, $offset, $pageSize);

        return [
            'current_page' => $page,
            'data' => $pagedRoots,
            'per_page' => $pageSize,
            'total' => $totalRoots,
            'last_page' => (int)ceil($totalRoots / $pageSize),
            'from' => $totalRoots > 0 ? $offset + 1 : null,
            'to' => $totalRoots > 0 ? min($offset + $pageSize, $totalRoots) : null,
        ];
    }
}
