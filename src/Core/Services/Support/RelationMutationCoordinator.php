<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelationMutationCoordinator
{
    private Closure $resolveConfig;
    private Closure $resolveParent;
    private Closure $resolveMutationParentAndRelatedId;
    private Closure $extractData;
    private Closure $executeMutation;
    private Closure $isBulkScenario;
    private Closure $buildNotFoundError;
    private Closure $buildBulkNotFoundError;

    public function __construct(
        callable $resolveConfig,
        callable $resolveParent,
        callable $resolveMutationParentAndRelatedId,
        callable $extractData,
        callable $executeMutation,
        callable $isBulkScenario,
        callable $buildNotFoundError,
        callable $buildBulkNotFoundError
    ) {
        $this->resolveConfig = Closure::fromCallable($resolveConfig);
        $this->resolveParent = Closure::fromCallable($resolveParent);
        $this->resolveMutationParentAndRelatedId = Closure::fromCallable($resolveMutationParentAndRelatedId);
        $this->extractData = Closure::fromCallable($extractData);
        $this->executeMutation = Closure::fromCallable($executeMutation);
        $this->isBulkScenario = Closure::fromCallable($isBulkScenario);
        $this->buildNotFoundError = Closure::fromCallable($buildNotFoundError);
        $this->buildBulkNotFoundError = Closure::fromCallable($buildBulkNotFoundError);
    }

    public function create(Request $request, mixed $parentId = null): JsonResponse
    {
        $config = ($this->resolveConfig)($request->get('_relation'));
        $parent = ($this->resolveParent)($config, $parentId);
        $data = ($this->extractData)($request, $config);
        $bulk = ($this->isBulkScenario)($request);

        return ($this->executeMutation)($request, function () use ($parent, $config, $data, $bulk) {
            $relationship = $parent->{$config['relationship']}();

            if ($bulk) {
                return ['success' => true, 'models' => $relationship->createMany($data)];
            }

            return ['success' => true, 'model' => $relationship->create($data)];
        }, 201);
    }

    public function update(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        $config = ($this->resolveConfig)($request->get('_relation'));
        $bulk = ($this->isBulkScenario)($request);
        $relationName = $request->get('_relation');

        [$parent, $relatedId] = ($this->resolveMutationParentAndRelatedId)(
            $config,
            $bulk,
            $parentIdOrRelatedId,
            $relatedId
        );

        $data = ($this->extractData)($request, $config);

        return ($this->executeMutation)($request, function () use ($parent, $config, $data, $bulk, $relatedId, $relationName) {
            $relationship = $parent->{$config['relationship']}();

            if ($bulk) {
                $ids = array_filter(array_column($data, 'id'));
                $relatedEntities = $relationship->find($ids)->keyBy('id');

                $results = [];
                $updatedIds = [];
                $notFoundIds = [];

                foreach ($data as $item) {
                    $id = $item['id'] ?? null;
                    $related = $id ? $relatedEntities->get($id) : null;

                    if ($related) {
                        $related->update($item);
                        $updatedIds[] = $id;
                    } elseif ($id !== null) {
                        $notFoundIds[] = $id;
                    }
                }

                if (!empty($updatedIds)) {
                    $refreshed = $relationship->find($updatedIds)->keyBy('id');
                    foreach ($updatedIds as $id) {
                        if ($refreshed->has($id)) {
                            $results['models'][] = $refreshed->get($id);
                        }
                    }
                }

                if (!empty($notFoundIds)) {
                    $results['error'] = ($this->buildBulkNotFoundError)(
                        class_basename($config['relatedModel']),
                        $notFoundIds,
                        $relationName
                    );
                }

                return array_merge(['success' => empty($notFoundIds)], $results);
            }

            $related = $relationship->find($relatedId);
            if (!$related) {
                return response()->json(
                    ($this->buildNotFoundError)(
                        class_basename($config['relatedModel']),
                        $relatedId,
                        $relationName
                    ),
                    404
                );
            }

            $related->update($data);
            return ['success' => true, 'model' => $related->refresh()];
        });
    }

    public function delete(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        $config = ($this->resolveConfig)($request->get('_relation'));
        $bulk = ($this->isBulkScenario)($request);
        $relationName = $request->get('_relation');
        $deleteRelated = $config['mutation']['deleteRelated'] ?? true;
        $isM2M = ($config['_type'] ?? 'o2m') === 'm2m';

        [$parent, $relatedId] = ($this->resolveMutationParentAndRelatedId)(
            $config,
            $bulk,
            $parentIdOrRelatedId,
            $relatedId
        );

        $data = ($this->extractData)($request, $config);

        return ($this->executeMutation)($request, function () use ($parent, $config, $data, $bulk, $relatedId, $relationName, $deleteRelated, $isM2M) {
            $relationship = $parent->{$config['relationship']}();

            if ($bulk) {
                if ($isM2M) {
                    $relatedKeyPlural = $config['relatedKey'] . 's';
                    if (isset($data[$relatedKeyPlural])) {
                        $ids = $data[$relatedKeyPlural];
                    } elseif (array_is_list($data)) {
                        $ids = $data;
                    } else {
                        $ids = collect($data)->flatten()->all();
                    }
                } else {
                    $ids = collect($data)->values()->all();
                }

                $relatedEntities = $relationship->find($ids);
                $foundIds = $relatedEntities->pluck('id')->all();
                $notFoundIds = array_values(array_diff($ids, $foundIds));

                if (!empty($foundIds)) {
                    if ($isM2M) {
                        $relationship->detach($foundIds);
                    }

                    if ($deleteRelated) {
                        $config['relatedModel']::whereIn('id', $foundIds)->delete();
                    }
                }

                $results['models'] = $relatedEntities;

                if (!empty($notFoundIds)) {
                    $results['error'] = ($this->buildBulkNotFoundError)(
                        class_basename($config['relatedModel']),
                        $notFoundIds,
                        $relationName
                    );
                }

                return array_merge(['success' => empty($notFoundIds)], $results);
            }

            $related = $relationship->find($relatedId);
            if (!$related) {
                return response()->json(
                    ($this->buildNotFoundError)(
                        class_basename($config['relatedModel']),
                        $relatedId,
                        $relationName
                    ),
                    404
                );
            }

            if ($isM2M) {
                $relationship->detach($relatedId);
            }

            if ($deleteRelated) {
                $related->delete();
            }

            return ['success' => true, 'model' => $related];
        });
    }
}
