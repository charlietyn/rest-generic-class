<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ronu\RestGenericClass\Core\Exports\ModelExport;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Trait ManagesRelations
 *
 * Unified trait that handles both one-to-many (HasMany) and many-to-many
 * (BelongsToMany) relationships with full filtering, pagination, ordering,
 * and configurable CRUD/pivot operations.
 *
 * Usage: add `use ManagesRelations;` and define `$oneToManyConfig` and/or
 * `$manyToManyConfig` in the controller.
 *
 * The route must inject `_relation` via the `inject` middleware so the trait knows
 * which relationship to resolve. For mutation routes, `_scenario` determines
 * single vs. bulk mode (any scenario containing "bulk" triggers bulk behaviour).
 *
 * The optional `{parent_id}` route parameter tells the trait to load a specific
 * parent; when absent the authenticated user is used (/site and /mobile channels).
 *
 * Many-to-many specific:
 * - The `sync` and `toggle` scenarios support three pivot data input shapes:
 *     - Flat list of scalar IDs:        [1, 2, 3]
 *     - List of objects with pivot cols: [{"address_id": 1, "is_primary": true}, ...]
 *     - Laravel-native assoc map:       {1: {"is_primary": true}, 2: {}}
 * - An optional `pivotColumns` whitelist in the mutation config restricts which
 *   pivot attributes are accepted across all attach scenarios.
 *
 * Configuration examples:
 *
 *  // One-to-many
 *  protected array $oneToManyConfig = [
 *      'states' => [
 *          'relationship'  => 'array_states',
 *          'relatedModel'  => States::class,
 *          'parentModel'   => Countries::class,
 *          'foreignKey'    => 'country_id',
 *          'localKey'      => 'id',
 *          'mutation' => [
 *              'dataKey'       => ['States', 'states'],
 *              'deleteRelated' => true,
 *          ],
 *      ],
 *  ];
 *
 *  // Many-to-many
 *  protected array $manyToManyConfig = [
 *      'addresses' => [
 *          'relationship'  => 'array_address',
 *          'relatedModel'  => Addresses::class,
 *          'pivotModel'    => UserAddresses::class,
 *          'parentModel'   => Users::class,
 *          'parentKey'     => 'user_id',
 *          'relatedKey'    => 'address_id',
 *          'mutation' => [
 *              'dataKey'       => ['Addresses', 'addresses'],
 *              'deleteRelated' => true,
 *              'pivotColumns'  => ['is_primary', 'label'],
 *          ],
 *      ],
 *  ];
 */
trait ManagesRelations
{
    // ──────────────────────────────────────────────────────────────
    //  Read entry-points
    // ──────────────────────────────────────────────────────────────

    /**
     * List the related entities of a relationship (O2M or M2M).
     *
     * Supports: eq/attr (equality), oper (complex filters), orderby, select,
     * pagination, and eager-loading of additional relations on the related model.
     */
    public function listRelation(Request $request, mixed $parentId = null): LengthAwarePaginator|array
    {
        $config = $this->resolveRelationConfig($request->get('_relation'));
        $parent = $this->resolveParentEntity($config, $parentId);

        $query  = $parent->{$config['relationship']}();
        $params = $this->parseRelationParams($request);

        $this->applyEqFilters($query, $params['eq']);
        $this->applyOperFilters($query, $params['oper']);
        $this->applyOrdering($query, $params['orderby']);

        if (!empty($params['relations'])) {
            $query->with($params['relations']);
        }

        if (!empty($params['pagination'])) {
            return $this->processRelationPagination($params, $query);
        }

        $value = $query->get();
        return ['data' => $value->jsonSerialize()];
    }

    /**
     * Show a single related entity from a relationship (O2M or M2M).
     *
     * Returns HTTP 404 with a structured error envelope when the resource is not found.
     *
     * Route shapes:
     *   /site & /mobile: entity/relation/{relatedId}              → parentIdOrRelatedId = relatedId, relatedId = null
     *   /admin:          entity/{parent_id}/relation/{relatedId}   → parentIdOrRelatedId = parentId,  relatedId = relatedId
     */
    public function showRelation(Request $request, mixed $parentIdOrRelatedId, mixed $relatedId = null): mixed
    {
        $config = $this->resolveRelationConfig($request->get('_relation'));

        if ($relatedId === null) {
            $parent    = $this->resolveParentEntity($config, null);
            $relatedId = $parentIdOrRelatedId;
        } else {
            $parent = $this->resolveParentEntity($config, $parentIdOrRelatedId);
        }

        $query  = $parent->{$config['relationship']}();
        $params = $this->parseRelationParams($request);

        if (!empty($params['relations'])) {
            $query->with($params['relations']);
        }

        $related = $query->find($relatedId, $params['select']);

        if (!$related) {
            return response()->json(
                $this->buildNotFoundError(
                    class_basename($config['relatedModel']),
                    $relatedId,
                    $request->get('_relation')
                ),
                404
            );
        }

        return $related;
    }

    // ──────────────────────────────────────────────────────────────
    //  Export entry-points
    // ──────────────────────────────────────────────────────────────

    /**
     * Export related entities to Excel.
     *
     * Applies the same filters/ordering as listRelation but fetches ALL matching
     * records (pagination is intentionally ignored for export operations).
     *
     * Requires: maatwebsite/excel.
     */
    public function exportRelationExcel(Request $request, mixed $parentId = null): mixed
    {
        $config   = $this->resolveRelationConfig($request->get('_relation'));
        $params   = $this->parseRelationParams($request);
        $data     = $this->buildExportData($config, $params, $parentId);
        $columns  = $this->resolveExportColumns($request, $config, $params['select']);
        $filename = $request->get('filename', 'export.xlsx');

        return \Maatwebsite\Excel\Facades\Excel::download(
            new ModelExport($data, $columns),
            $filename
        );
    }

    /**
     * Export related entities to PDF.
     *
     * Requires: barryvdh/laravel-dompdf.
     */
    public function exportRelationPdf(Request $request, mixed $parentId = null): mixed
    {
        $config   = $this->resolveRelationConfig($request->get('_relation'));
        $params   = $this->parseRelationParams($request);
        $data     = $this->buildExportData($config, $params, $parentId);
        $columns  = $this->resolveExportColumns($request, $config, $params['select']);
        $template = $request->get('template', 'pdf');
        $filename = $request->get('filename', 'export.pdf');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView($template, [
            'data'    => $data,
            'columns' => $columns,
            'model'   => new $config['relatedModel'],
            'params'  => $params,
        ])->download($filename);
    }

    // ──────────────────────────────────────────────────────────────
    //  Mutation entry-points (shared O2M + M2M)
    // ──────────────────────────────────────────────────────────────

    /**
     * Create a related entity through a relationship (O2M or M2M).
     *
     * Single or bulk mode is determined by `_scenario`: any scenario containing
     * "bulk" triggers createMany(); otherwise create().
     *
     * Returns HTTP 201 Created on success.
     */
    public function createRelation(Request $request, mixed $parentId = null): JsonResponse
    {
        $config = $this->resolveRelationConfig($request->get('_relation'));
        $parent = $this->resolveParentEntity($config, $parentId);
        $data   = $this->extractMutationData($request, $config);
        $bulk   = $this->isBulkScenario($request);

        return $this->executeMutation($request, function () use ($parent, $config, $data, $bulk) {
            $relationship = $parent->{$config['relationship']}();

            if ($bulk) {
                return ['success' => true, 'models' => $relationship->createMany($data)];
            }

            return ['success' => true, 'model' => $relationship->create($data)];
        }, 201);
    }

    /**
     * Update a related entity through a relationship (O2M or M2M).
     *
     * Route shapes:
     *   /site & /mobile: entity/relation/{relatedId}            → single
     *   /admin:          entity/{parent_id}/relation/{relatedId} → single
     *   bulk (any):      entity/relation/bulk                    → IDs in body
     */
    public function updateRelation(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        $config       = $this->resolveRelationConfig($request->get('_relation'));
        $bulk         = $this->isBulkScenario($request);
        $relationName = $request->get('_relation');

        [$parent, $relatedId] = $this->resolveMutationParentAndRelatedId(
            $config, $bulk, $parentIdOrRelatedId, $relatedId
        );

        $data = $this->extractMutationData($request, $config);

        return $this->executeMutation($request, function () use ($parent, $config, $data, $bulk, $relatedId, $relationName) {
            $relationship = $parent->{$config['relationship']}();

            if ($bulk) {
                $ids             = array_filter(array_column($data, 'id'));
                $relatedEntities = $relationship->find($ids)->keyBy('id');

                $results     = [];
                $updatedIds  = [];
                $notFoundIds = [];

                foreach ($data as $item) {
                    $id      = $item['id'] ?? null;
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
                    $results['error'] = $this->buildBulkNotFoundError(
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
                    $this->buildNotFoundError(
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

    /**
     * Delete a related entity from a relationship (O2M or M2M).
     *
     * For M2M relations, the pivot row is detached before the related model is deleted.
     * When `mutation.deleteRelated` is true (default), the related model is deleted.
     * When false for M2M, only the pivot row is removed.
     */
    public function deleteRelation(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        $config        = $this->resolveRelationConfig($request->get('_relation'));
        $bulk          = $this->isBulkScenario($request);
        $relationName  = $request->get('_relation');
        $deleteRelated = $config['mutation']['deleteRelated'] ?? true;
        $isM2M         = ($config['_type'] ?? 'o2m') === 'm2m';

        [$parent, $relatedId] = $this->resolveMutationParentAndRelatedId(
            $config, $bulk, $parentIdOrRelatedId, $relatedId
        );

        $data = $this->extractMutationData($request, $config);

        return $this->executeMutation($request, function () use ($parent, $config, $data, $bulk, $relatedId, $relationName, $deleteRelated, $isM2M) {
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
                $foundIds        = $relatedEntities->pluck('id')->all();
                $notFoundIds     = array_values(array_diff($ids, $foundIds));

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
                    $results['error'] = $this->buildBulkNotFoundError(
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
                    $this->buildNotFoundError(
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

    // ──────────────────────────────────────────────────────────────
    //  M2M-exclusive mutation entry-points
    // ──────────────────────────────────────────────────────────────

    /**
     * Attach existing related entities (pivot-only operation, M2M only).
     *
     * Scenarios:
     *   attach      → single ID with optional pivot data
     *   bulk_attach → multiple IDs with optional pivot data
     *   sync        → replace the entire relationship set
     *   toggle      → toggle specific IDs
     */
    public function attachRelation(Request $request, mixed $parentId = null): JsonResponse
    {
        $config = $this->resolveRelationConfig($request->get('_relation'));
        $this->assertManyToMany($config, 'attachRelation');

        $parent           = $this->resolveParentEntity($config, $parentId);
        $data             = $this->extractMutationData($request, $config);
        $scenario         = $request->get('_scenario', 'attach');
        $relatedKey       = $config['relatedKey'];
        $allowedPivotCols = $config['mutation']['pivotColumns'] ?? [];

        return $this->executeMutation($request, function () use ($parent, $config, $data, $scenario, $relatedKey, $allowedPivotCols) {
            $relationship = $parent->{$config['relationship']}();

            return match (true) {
                str_contains($scenario, 'sync')   => $this->processSyncAttach($relationship, $data, $relatedKey, $allowedPivotCols),
                str_contains($scenario, 'toggle') => $this->processToggleAttach($relationship, $data, $relatedKey, $allowedPivotCols),
                str_contains($scenario, 'bulk')   => $this->processBulkAttach($relationship, $data, $relatedKey, $allowedPivotCols),
                default                            => $this->processSingleAttach($relationship, $data, $relatedKey, $allowedPivotCols),
            };
        });
    }

    /**
     * Detach related entities (pivot-only removal, no model deletion, M2M only).
     *
     * Scenarios:
     *   detach      → single ID
     *   bulk_detach → multiple IDs
     */
    public function detachRelation(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        $config = $this->resolveRelationConfig($request->get('_relation'));
        $this->assertManyToMany($config, 'detachRelation');

        $bulk = $this->isBulkScenario($request);

        [$parent, $relatedId] = $this->resolveMutationParentAndRelatedId(
            $config, $bulk, $parentIdOrRelatedId, $relatedId
        );

        $data = $this->extractMutationData($request, $config);

        return $this->executeMutation($request, function () use ($parent, $config, $data, $bulk, $relatedId) {
            $relationship = $parent->{$config['relationship']}();

            return ['detached' => $relationship->detach($bulk ? $data : $relatedId)];
        });
    }

    /**
     * Update pivot table fields only (without modifying the related model, M2M only).
     *
     * Scenarios:
     *   update_pivot      → single related ID
     *   bulk_update_pivot → multiple related IDs
     */
    public function updatePivotRelation(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        $config = $this->resolveRelationConfig($request->get('_relation'));
        $this->assertManyToMany($config, 'updatePivotRelation');

        $bulk       = $this->isBulkScenario($request);
        $relatedKey = $config['relatedKey'];

        [$parent, $relatedId] = $this->resolveMutationParentAndRelatedId(
            $config, $bulk, $parentIdOrRelatedId, $relatedId
        );

        $data = $this->extractMutationData($request, $config);

        return $this->executeMutation($request, function () use ($parent, $config, $data, $bulk, $relatedId, $relatedKey) {
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

    // ──────────────────────────────────────────────────────────────
    //  Pagination
    // ──────────────────────────────────────────────────────────────

    /**
     * Apply pagination to the query based on the given parameters.
     */
    private function paginateRelationQuery($query, $pagination, $select): LengthAwarePaginator
    {
        if (is_string($pagination)) {
            $pagination = json_decode($pagination, true);
        }

        $currentPage = $pagination['page'] ?? 1;
        $pageSize    = $pagination['pageSize'] ?? ($pagination['pagesize'] ?? null);

        Paginator::currentPageResolver(function () use ($currentPage) {
            return $currentPage;
        });

        return $query->paginate($pageSize, $select);
    }

    /**
     * Process pagination for the query based on the given parameters.
     */
    public function processRelationPagination($params, $query): mixed
    {
        $pagination_lower = array_change_key_case($params['pagination']);
        $pagesize = array_key_exists('pagesize', $pagination_lower) ? $pagination_lower['pagesize'] : $this->modelClass->getPerPage();

        if (!isset($params['pagination']['infinity']) || $params['pagination']['infinity'] !== true) {
            return $this->paginateRelationQuery($query, $params['pagination'], $params['select']);
        }

        $cursor = $params['pagination']['cursor'] ?? null;
        $items  = $query->cursorPaginate($pagesize, $params['select'], 'cursor', $cursor);

        return [
            'data'        => $items->items(),
            'next_cursor' => $items->nextCursor()?->encode(),
            'has_more'    => $items->hasMorePages(),
        ];
    }

    /**
     * Backward-compatible alias for processRelationPagination.
     */
    public function process_pagination($params, $query): mixed
    {
        return $this->processRelationPagination($params, $query);
    }

    // ──────────────────────────────────────────────────────────────
    //  Mutation helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Resolve parent entity and relatedId for mutation operations.
     */
    protected function resolveMutationParentAndRelatedId(
        array $config,
        bool  $bulk,
        mixed $parentIdOrRelatedId,
        mixed $relatedId
    ): array {
        if ($bulk) {
            return [$this->resolveParentEntity($config, $parentIdOrRelatedId), null];
        }

        if ($relatedId === null) {
            return [$this->resolveParentEntity($config, null), $parentIdOrRelatedId];
        }

        return [$this->resolveParentEntity($config, $parentIdOrRelatedId), $relatedId];
    }

    /**
     * Extract mutation data from request body, trying configured dataKey(s).
     */
    protected function extractMutationData(Request $request, array $config): array
    {
        $params = count($request->all()) != 0
            ? $request->all()
            : (json_decode($request->getContent(), true) ?? []);

        $dataKeys = $config['mutation']['dataKey'] ?? [];
        if (is_string($dataKeys)) {
            $dataKeys = [$dataKeys];
        }

        foreach ($dataKeys as $key) {
            if (isset($params[$key])) {
                return $params[$key];
            }
        }

        unset($params['_relation'], $params['_scenario']);
        return $params;
    }

    /**
     * Execute a mutation inside a DB transaction with error logging.
     */
    protected function executeMutation(Request $request, callable $operation, int $status = 200): JsonResponse
    {
        DB::beginTransaction();
        try {
            $result = $operation();
            DB::commit();

            if ($result instanceof JsonResponse) {
                return $result;
            }

            return response()->json($result, $status);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Relation mutation failed on ' . ($request->get('_relation') ?? 'unknown') . ' relation', [
                'controller' => static::class,
                'scenario'   => $request->get('_scenario'),
                'exception'  => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Check whether the current request is a bulk operation.
     */
    protected function isBulkScenario(Request $request): bool
    {
        return str_contains($request->get('_scenario', ''), 'bulk');
    }

    // ──────────────────────────────────────────────────────────────
    //  M2M attach helpers
    // ──────────────────────────────────────────────────────────────

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
        $result   = $relationship->sync($pivotMap);

        return [
            'attached' => $result['attached'],
            'detached' => $result['detached'],
            'updated'  => $result['updated'],
        ];
    }

    private function processToggleAttach(BelongsToMany $relationship, array $data, string $relatedKey, array $allowedPivotCols = []): array
    {
        $pivotMap = $this->buildPivotMap($data, $relatedKey, $allowedPivotCols);
        $result   = $relationship->toggle($pivotMap);

        return [
            'attached' => $result['attached'],
            'detached' => $result['detached'],
        ];
    }

    /**
     * Normalize heterogeneous input into a Laravel-compatible pivot map.
     *
     * Supports three input shapes:
     *   Shape 1 — flat list of scalar IDs:  [1, 2, 3]
     *   Shape 2 — list of objects with relatedKey + pivot cols
     *   Shape 3 — already a Laravel-native assoc map
     */
    private function buildPivotMap(array $data, string $relatedKey, array $allowedPivotCols = []): array
    {
        $whitelist = !empty($allowedPivotCols) ? array_flip($allowedPivotCols) : [];

        // Shape 3: already an assoc map
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
            // Shape 1: scalar ID
            if (!is_array($item)) {
                $map[$item] = [];
                continue;
            }

            // Shape 2: object with relatedKey + optional pivot columns
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

    // ──────────────────────────────────────────────────────────────
    //  Resolution helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Resolve the config for a given relation name.
     *
     * Checks both `$oneToManyConfig` and `$manyToManyConfig` and tags the result
     * with `_type` ('o2m' or 'm2m') so downstream logic can differentiate.
     */
    protected function resolveRelationConfig(?string $relationName): array
    {
        if ($relationName) {
            if (property_exists($this, 'oneToManyConfig') && isset($this->oneToManyConfig[$relationName])) {
                return array_merge($this->oneToManyConfig[$relationName], ['_type' => 'o2m']);
            }

            if (property_exists($this, 'manyToManyConfig') && isset($this->manyToManyConfig[$relationName])) {
                return array_merge($this->manyToManyConfig[$relationName], ['_type' => 'm2m']);
            }
        }

        throw new BadRequestHttpException(
            "Relation '{$relationName}' is not configured on " . static::class
        );
    }

    protected function resolveParentEntity(array $config, mixed $parentId): mixed
    {
        if ($parentId !== null) {
            $parentModel = new $config['parentModel'];
            $parent = $parentModel->find($parentId);

            if (!$parent) {
                throw new NotFoundHttpException(
                    class_basename($config['parentModel']) . " with id {$parentId} not found"
                );
            }

            return $parent;
        }

        $user = auth()->user();

        if (!$user) {
            throw new NotFoundHttpException('Authenticated user not found');
        }

        return $user;
    }

    /**
     * Assert that a relation is configured as many-to-many.
     */
    protected function assertManyToMany(array $config, string $method): void
    {
        if (($config['_type'] ?? 'o2m') !== 'm2m') {
            throw new BadRequestHttpException(
                "{$method}() can only be used with many-to-many relations. " .
                "Configure the relation in \$manyToManyConfig instead of \$oneToManyConfig."
            );
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Request parsing
    // ──────────────────────────────────────────────────────────────

    protected function parseRelationParams(Request $request): array
    {
        return [
            'eq'         => $this->parseRelationJsonParam($request, 'eq', 'attr'),
            'oper'       => $this->parseRelationJsonParam($request, 'oper'),
            'orderby'    => $this->parseRelationJsonParam($request, 'orderby'),
            'pagination' => $this->parseRelationJsonParam($request, 'pagination'),
            'select'     => $this->parseRelationSelect($request),
            'relations'  => $this->parseRelationRelations($request),
        ];
    }

    private function parseRelationJsonParam(Request $request, string $key, ?string $alias = null): array
    {
        $value   = $request->get($key);
        $decoded = is_string($value) ? (json_decode($value, true) ?? []) : ($value ?? []);

        if ($alias) {
            $aliasValue   = $request->get($alias);
            $aliasDecoded = is_string($aliasValue) ? (json_decode($aliasValue, true) ?? []) : ($aliasValue ?? []);
            $decoded      = array_merge($decoded, $aliasDecoded);
        }

        return $decoded;
    }

    private function parseRelationSelect(Request $request): array
    {
        $select = $request->get('select');

        if (!$select) {
            return ['*'];
        }

        if (is_string($select)) {
            $decoded = json_decode($select, true);
            return $decoded ?: explode(',', $select);
        }

        return (array)$select;
    }

    private function parseRelationRelations(Request $request): array
    {
        $relations = $request->get('relations');

        if (!$relations) {
            return [];
        }

        if (is_string($relations)) {
            return json_decode($relations, true) ?? [];
        }

        return (array)$relations;
    }

    // ──────────────────────────────────────────────────────────────
    //  Query application
    // ──────────────────────────────────────────────────────────────

    /**
     * Apply equality filters: { "field": "value" } or { "field": [1,2,3] }
     */
    protected function applyEqFilters(Relation $query, array $eq): void
    {
        foreach ($eq as $field => $value) {
            if ($field === '_relation' || $field === '_scenario') {
                continue;
            }
            if (is_array($value)) {
                $query->whereIn($field, $value);
            } elseif ($value === null) {
                $query->whereNull($field);
            } else {
                $query->where($field, '=', $value);
            }
        }
    }

    /**
     * Apply complex oper filters.
     *
     * Supports format:
     *   { "and": ["field operator value", ...], "or": ["field operator value", ...] }
     */
    protected function applyOperFilters(Relation $query, array $oper): void
    {
        if (empty($oper)) {
            return;
        }

        if (isset($oper['and'])) {
            $query->where(function ($q) use ($oper) {
                foreach ($oper['and'] as $condition) {
                    $this->applySingleCondition($q, $condition, 'and');
                }
            });
        }

        if (isset($oper['or'])) {
            $query->where(function ($q) use ($oper) {
                foreach ($oper['or'] as $condition) {
                    $this->applySingleCondition($q, $condition, 'or');
                }
            });
        }
    }

    /**
     * Parse a single condition string: "field operator value"
     *
     * Supported operators: =, !=, <, >, <=, >=, like, not like, ilike, not ilike,
     * in, not in, between, not between, null, not null
     */
    protected function applySingleCondition($query, string $condition, string $boolean): void
    {
        $condition = trim($condition);

        // Handle "null" / "not null" operators
        if (preg_match('/^(.+?)\s+(not\s+null|null)$/i', $condition, $m)) {
            $field = trim($m[1]);
            $op = strtolower(trim($m[2]));

            if ($op === 'null') {
                $query->whereNull($field, $boolean);
            } else {
                $query->whereNotNull($field, $boolean);
            }
            return;
        }

        // Handle "between" / "not between"
        if (preg_match('/^(.+?)\s+(not\s+between|between)\s+(.+)$/i', $condition, $m)) {
            $field  = trim($m[1]);
            $op     = strtolower(trim($m[2]));
            $values = array_map('trim', explode(',', $m[3]));

            if (count($values) === 2) {
                if ($op === 'between') {
                    $query->whereBetween($field, $values, $boolean);
                } else {
                    $query->whereNotBetween($field, $values, $boolean);
                }
            }
            return;
        }

        // Handle "in" / "not in"
        if (preg_match('/^(.+?)\s+(not\s+in|in)\s+(.+)$/i', $condition, $m)) {
            $field     = trim($m[1]);
            $op        = strtolower(trim($m[2]));
            $rawValues = trim($m[3], '[] ');
            $values    = array_map('trim', explode(',', $rawValues));

            if ($op === 'in') {
                $query->whereIn($field, $values, $boolean);
            } else {
                $query->whereNotIn($field, $values, $boolean);
            }
            return;
        }

        // Handle comparison and pattern operators
        $operators = ['not ilike', 'not like', 'ilike', 'like', '!=', '>=', '<=', '>', '<', '='];

        foreach ($operators as $op) {
            $pos = stripos($condition, " {$op} ");
            if ($pos !== false) {
                $field = trim(substr($condition, 0, $pos));
                $value = trim(substr($condition, $pos + strlen($op) + 2));

                if (in_array(strtolower($op), ['like', 'not like'])) {
                    $value  = str_contains($value, '%') ? $value : "%{$value}%";
                    $method = $boolean === 'or' ? 'orWhere' : 'where';
                    $query->{$method}($field, $op, $value);
                } elseif (in_array(strtolower($op), ['ilike', 'not ilike'])) {
                    $value    = str_contains($value, '%') ? $value : "%{$value}%";
                    $method   = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
                    $negation = str_starts_with(strtolower($op), 'not') ? 'NOT ' : '';
                    $query->{$method}("{$field} {$negation}ILIKE ?", [$value]);
                } else {
                    if ($boolean === 'or') {
                        $query->orWhere($field, $op, $value);
                    } else {
                        $query->where($field, $op, $value);
                    }
                }
                return;
            }
        }
    }

    /**
     * Apply ordering: [{"field":"asc"}, {"field2":"desc"}]
     */
    protected function applyOrdering(Relation $query, array $orderby): void
    {
        foreach ($orderby as $item) {
            if (is_array($item)) {
                foreach ($item as $column => $direction) {
                    $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
                    $query->orderBy($column, $direction);
                }
            }
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Export helpers
    // ──────────────────────────────────────────────────────────────

    private function buildExportData(array $config, array $params, mixed $parentId): array
    {
        $parent = $this->resolveParentEntity($config, $parentId);
        $query  = $parent->{$config['relationship']}();

        $this->applyEqFilters($query, $params['eq']);
        $this->applyOperFilters($query, $params['oper']);
        $this->applyOrdering($query, $params['orderby']);

        if (!empty($params['relations'])) {
            $query->with($params['relations']);
        }

        return $query->get()->toArray();
    }

    /**
     * Resolve the column list for a relation export.
     *
     * Priority:
     *   1. 'columns' query param
     *   2. 'select'  query param (when not ['*'])
     *   3. relatedModel::getFillable()
     */
    private function resolveExportColumns(Request $request, array $config, array $select): array
    {
        $rawColumns = $request->get('columns');

        if ($rawColumns) {
            $normalized = $this->normalizeExportColumns($rawColumns);
            if (!empty($normalized)) {
                return $normalized;
            }
        }

        if ($select !== ['*']) {
            $normalized = $this->normalizeExportColumns($select);
            if (!empty($normalized)) {
                return $normalized;
            }
        }

        return (new $config['relatedModel'])->getFillable();
    }

    private function normalizeExportColumns(mixed $columns): array
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }

        if (!is_array($columns)) {
            return [];
        }

        return array_values(array_filter($columns, static fn($v) => $v !== ''));
    }

    // ──────────────────────────────────────────────────────────────
    //  Not-found error builders
    // ──────────────────────────────────────────────────────────────

    private function buildNotFoundError(string $modelName, mixed $id, string $relation): array
    {
        return [
            'success' => false,
            'error'   => [
                'message'       => "{$modelName} with id {$id} not found in relation '{$relation}'",
                'relation'      => $relation,
                'id'            => $id,
                'suggested_fix' => "Verify the resource exists via GET on the '{$relation}' endpoint"
                    . " before performing write operations on it.",
            ],
        ];
    }

    private function buildBulkNotFoundError(string $modelName, array $notFoundIds, string $relation): array
    {
        $count = count($notFoundIds);

        return [
            'message'       => "{$count} {$modelName} record(s) not found in relation '{$relation}'"
                . ': [' . implode(', ', $notFoundIds) . ']',
            'relation'      => $relation,
            'not_found_ids' => $notFoundIds,
            'suggested_fix' => "Fetch the current list via GET on the '{$relation}' endpoint"
                . " to obtain valid IDs, then retry with only the existing records.",
        ];
    }
}
