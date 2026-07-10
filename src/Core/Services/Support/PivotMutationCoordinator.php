<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Closure;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PivotMutationCoordinator
{
    private Closure $resolveConfig;
    private Closure $assertManyToMany;
    private Closure $resolveParent;
    private Closure $resolveMutationParentAndRelatedId;
    private Closure $extractData;
    private Closure $executeMutation;
    private Closure $isBulkScenario;

    public function __construct(
        callable $resolveConfig,
        callable $assertManyToMany,
        callable $resolveParent,
        callable $resolveMutationParentAndRelatedId,
        callable $extractData,
        callable $executeMutation,
        callable $isBulkScenario
    ) {
        $this->resolveConfig = Closure::fromCallable($resolveConfig);
        $this->assertManyToMany = Closure::fromCallable($assertManyToMany);
        $this->resolveParent = Closure::fromCallable($resolveParent);
        $this->resolveMutationParentAndRelatedId = Closure::fromCallable($resolveMutationParentAndRelatedId);
        $this->extractData = Closure::fromCallable($extractData);
        $this->executeMutation = Closure::fromCallable($executeMutation);
        $this->isBulkScenario = Closure::fromCallable($isBulkScenario);
    }

    public function attach(Request $request, mixed $parentId = null): JsonResponse
    {
        $config = ($this->resolveConfig)($request->get('_relation'));
        ($this->assertManyToMany)($config, 'attachRelation');

        $parent = ($this->resolveParent)($config, $parentId);
        $data = ($this->extractData)($request, $config);
        $scenario = $request->get('_scenario', 'attach');
        $relatedKey = $config['relatedKey'];
        $allowedPivotCols = $config['mutation']['pivotColumns'] ?? [];

        return ($this->executeMutation)($request, function () use ($parent, $config, $data, $scenario, $relatedKey, $allowedPivotCols) {
            $relationship = $parent->{$config['relationship']}();

            return match (true) {
                str_contains($scenario, 'sync') => $this->processSyncAttach($relationship, $data, $relatedKey, $allowedPivotCols),
                str_contains($scenario, 'toggle') => $this->processToggleAttach($relationship, $data, $relatedKey, $allowedPivotCols),
                str_contains($scenario, 'bulk') => $this->processBulkAttach($relationship, $data, $relatedKey, $allowedPivotCols),
                default => $this->processSingleAttach($relationship, $data, $relatedKey, $allowedPivotCols),
            };
        });
    }

    public function detach(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        $config = ($this->resolveConfig)($request->get('_relation'));
        ($this->assertManyToMany)($config, 'detachRelation');

        $bulk = ($this->isBulkScenario)($request);

        [$parent, $relatedId] = ($this->resolveMutationParentAndRelatedId)(
            $config,
            $bulk,
            $parentIdOrRelatedId,
            $relatedId
        );

        $data = ($this->extractData)($request, $config);

        return ($this->executeMutation)($request, function () use ($parent, $config, $data, $bulk, $relatedId) {
            $relationship = $parent->{$config['relationship']}();

            return ['detached' => $relationship->detach($bulk ? $data : $relatedId)];
        });
    }

    public function updatePivot(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        $config = ($this->resolveConfig)($request->get('_relation'));
        ($this->assertManyToMany)($config, 'updatePivotRelation');

        $bulk = ($this->isBulkScenario)($request);
        $relatedKey = $config['relatedKey'];

        [$parent, $relatedId] = ($this->resolveMutationParentAndRelatedId)(
            $config,
            $bulk,
            $parentIdOrRelatedId,
            $relatedId
        );

        $data = ($this->extractData)($request, $config);

        return ($this->executeMutation)($request, function () use ($parent, $config, $data, $bulk, $relatedId, $relatedKey) {
            $relationship = $parent->{$config['relationship']}();

            if ($bulk) {
                $results = [];
                foreach ($data as $item) {
                    $id = $item[$relatedKey] ?? $item['id'] ?? null;
                    if ($id) {
                        unset($item[$relatedKey], $item['id']);
                        $relationship->updateExistingPivot($id, $item);
                        $results[] = $relationship->find($id);
                    }
                }
                return $results;
            }

            $relationship->updateExistingPivot($relatedId, $data);
            return $relationship->find($relatedId);
        });
    }

    private function processSingleAttach(BelongsToMany $relationship, array $data, string $relatedKey, array $allowedPivotCols = []): array
    {
        $id = $data[$relatedKey] ?? $data['id'] ?? null;
        $pivotData = $data;
        unset($pivotData[$relatedKey], $pivotData['id']);

        if (!empty($allowedPivotCols)) {
            $pivotData = array_intersect_key($pivotData, array_flip($allowedPivotCols));
        }

        $relationship->attach($id, $pivotData);
        return ['attached' => [$id]];
    }

    private function processBulkAttach(BelongsToMany $relationship, array $data, string $relatedKey, array $allowedPivotCols = []): array
    {
        $attachData = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $id = $item[$relatedKey] ?? $item['id'] ?? null;
                $pivotData = $item;
                unset($pivotData[$relatedKey], $pivotData['id']);

                if (!empty($allowedPivotCols)) {
                    $pivotData = array_intersect_key($pivotData, array_flip($allowedPivotCols));
                }

                $attachData[$id] = $pivotData;
            } else {
                $attachData[] = $item;
            }
        }

        $relationship->attach($attachData);
        return ['attached' => array_keys($attachData)];
    }

    private function processSyncAttach(BelongsToMany $relationship, array $data, string $relatedKey, array $allowedPivotCols = []): array
    {
        $pivotMap = $this->buildPivotMap($data, $relatedKey, $allowedPivotCols);
        $result = $relationship->sync($pivotMap);

        return [
            'attached' => $result['attached'],
            'detached' => $result['detached'],
            'updated' => $result['updated'],
        ];
    }

    private function processToggleAttach(BelongsToMany $relationship, array $data, string $relatedKey, array $allowedPivotCols = []): array
    {
        $pivotMap = $this->buildPivotMap($data, $relatedKey, $allowedPivotCols);
        $result = $relationship->toggle($pivotMap);

        return [
            'attached' => $result['attached'],
            'detached' => $result['detached'],
        ];
    }

    private function buildPivotMap(array $data, string $relatedKey, array $allowedPivotCols = []): array
    {
        $whitelist = !empty($allowedPivotCols) ? array_flip($allowedPivotCols) : [];

        if (!array_is_list($data)) {
            return array_map(
                function (mixed $pivotCols) use ($whitelist): array {
                    if (!is_array($pivotCols)) {
                        return [];
                    }

                    return !empty($whitelist)
                        ? array_intersect_key($pivotCols, $whitelist)
                        : $pivotCols;
                },
                $data
            );
        }

        $map = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                $map[$item] = [];
                continue;
            }

            $id = $item[$relatedKey] ?? $item['id'] ?? null;
            $pivotData = $item;
            unset($pivotData[$relatedKey], $pivotData['id']);

            if (!empty($whitelist)) {
                $pivotData = array_intersect_key($pivotData, $whitelist);
            }

            $map[$id] = $pivotData;
        }

        return $map;
    }
}
