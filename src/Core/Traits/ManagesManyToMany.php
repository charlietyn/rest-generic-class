<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Trait ManagesManyToMany
 *
 * Provides generic read and mutation methods for controllers that need to expose
 * many-to-many relationships with full filtering, pagination, ordering, and
 * configurable CRUD/pivot operations.
 *
 * Usage: add `use ManagesManyToMany;` and define `$manyToManyConfig` in the controller.
 *
 * The route must inject `_relation` via the `inject` middleware so the trait knows
 * which relationship to resolve. For mutation routes, `_scenario` determines
 * single vs. bulk mode (any scenario containing "bulk" triggers bulk behaviour).
 *
 * The optional `{parent_id}` route parameter tells the trait to load a specific
 * parent; when absent the authenticated user is used (/site and /mobile channels).
 */
trait ManagesManyToMany
{
    /**
     * Configuration map — must be defined by the controller that uses the trait.
     *
     * Expected structure:
     *
     *  protected array $manyToManyConfig = [
     *      'addresses' => [
     *          'relationship'  => 'array_address',      // BelongsToMany method on parent
     *          'relatedModel'  => Addresses::class,
     *          'pivotModel'    => UserAddresses::class,
     *          'parentModel'   => Users::class,
     *          'parentKey'     => 'user_id',
     *          'relatedKey'    => 'address_id',
     *
     *          // Optional mutation settings (all keys optional, sensible defaults apply)
     *          'mutation' => [
     *              'dataKey'       => ['Addresses', 'addresses'], // keys to try extracting from body
     *              'deleteRelated' => true,                       // delete related model on deleteRelation?
     *          ],
     *      ],
     *  ];
     */

    // ──────────────────────────────────────────────────────────────
    //  Public entry-point
    // ──────────────────────────────────────────────────────────────

    /**
     * List the related entities of a many-to-many relationship.
     *
     * Supports: eq/attr (equality), oper (complex filters), orderby, select,
     * pagination, and eager-loading of additional relations on the related model.
     *
     * Pivot columns are automatically included in every record thanks to the
     * BelongsToMany->withPivot() already configured on the model.
     */
    public function listRelation(Request $request, mixed $parentId = null): LengthAwarePaginator|array
    {
        $config = $this->resolveRelationConfig($request->get('_relation'));
        $parent = $this->resolveParentEntity($config, $parentId);

        /** @var BelongsToMany $query */
        $query = $parent->{$config['relationship']}();

        $params = $this->parseManyToManyParams($request);

        $this->applyManyToManyEqFilters($query, $params['eq']);
        $this->applyManyToManyOperFilters($query, $params['oper']);
        $this->applyManyToManyOrdering($query, $params['orderby']);

        if (!empty($params['relations'])) {
            $query->with($params['relations']);
        }

//        if ($this->isInfinityPagination($params['pagination'])) {
//            return $query->get($params['select']);
//        }

        if (!empty($params['pagination'])) {
            return $this->process_pagination($params, $query);
        }
        $value = $query->get();
        return ['data' => $value->jsonSerialize()];
    }

    /**
     * Apply pagination to the query based on the given parameters.
     * @param $query
     * @param $pagination
     * @param $select
     * @return LengthAwarePaginator
     */
    private function pagination($query, $pagination, $select): LengthAwarePaginator
    {
        if (is_string($pagination))
            $pagination = json_decode($pagination, true);
        $currentPage = isset($pagination["page"]) ? $pagination["page"] : 1;
        $pageSize = isset($pagination["pageSize"]) ? $pagination["pageSize"] : (isset($pagination["pagesize"]) ? $pagination["pagesize"] : null);
        Paginator::currentPageResolver(function () use ($currentPage) {
            return $currentPage;
        });
        return $query->paginate($pageSize, $select);
    }

    /**
     * Process pagination for the query based on the given parameters.
     * @param $params
     * @param $query
     * @return mixed
     */
    public function process_pagination($params, $query): mixed
    {
        $pagination_lower = array_change_key_case($params['pagination']);
        $pagesize = array_key_exists('pagesize', $pagination_lower) ? $pagination_lower['pagesize'] : $this->modelClass->getPerPage();
        if (!isset($params['pagination']['infinity']) || $params['pagination']['infinity'] !== true)
            return $this->pagination($query, $params['pagination'], $params['select']);
        else {
            $cursor = isset($params['pagination']['cursor']) ? $params['pagination']['cursor'] : null;
            $items = $query->cursorPaginate($pagesize, $params['select'], 'cursor', $cursor);
            return [
                'data' => $items->items(),
                'next_cursor' => $items->nextCursor()?->encode(),
                'has_more' => $items->hasMorePages(),
            ];
        }
    }

    /**
     * Show a single related entity from a many-to-many relationship.
     *
     * Returns HTTP 404 with a structured error envelope when the resource is not found,
     * instead of throwing an exception, so the caller always receives a consistent JSON body.
     *
     * Route shapes:
     *   /site & /mobile: entity/relation/{relatedId}        → parentIdOrRelatedId = relatedId, relatedId = null
     *   /admin:          entity/{parent_id}/relation/{relatedId} → parentIdOrRelatedId = parentId, relatedId = relatedId
     */
    public function showRelation(Request $request, mixed $parentIdOrRelatedId, mixed $relatedId = null): mixed
    {
        $config = $this->resolveRelationConfig($request->get('_relation'));

        // Determine which param is parentId and which is relatedId
        if ($relatedId === null) {
            // Frontend/Mobile: no parent_id in route, user from auth
            $parent = $this->resolveParentEntity($config, null);
            $relatedId = $parentIdOrRelatedId;
        } else {
            // Admin: parent_id explicitly in route
            $parent = $this->resolveParentEntity($config, $parentIdOrRelatedId);
        }

        /** @var BelongsToMany $query */
        $query = $parent->{$config['relationship']}();

        $params = $this->parseManyToManyParams($request);

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
    //  Mutation entry-points
    // ──────────────────────────────────────────────────────────────

    /**
     * Create a related entity through a many-to-many relationship.
     *
     * Single or bulk mode is determined by `_scenario`: any scenario containing
     * "bulk" triggers createMany(); otherwise create().
     *
     * Returns HTTP 201 Created on success (REST compliant).
     *
     * Route shapes:
     *   /site & /mobile: entity/relation            → parentId = null (auth user)
     *   /admin:          entity/{parent_id}/relation → parentId from route
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
     * Update a related entity through a many-to-many relationship.
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
                // 1 query: load all related entities at once (avoids N find() queries)
                $ids             = array_filter(array_column($data, 'id'));
                $relatedEntities = $relationship->find($ids)->keyBy('id');

                $results     = [];
                $updatedIds  = [];
                $notFoundIds = [];

                foreach ($data as $item) {
                    $id      = $item['id'] ?? null;
                    $related = $id ? $relatedEntities->get($id) : null;
                    if ($related) {
                        $related->update($item);          // N queries (unavoidable: each row has different data)
                        $updatedIds[] = $id;
                    } elseif ($id !== null) {
                        $notFoundIds[] = $id;
                    }
                }

                // 1 query: refresh all updated entities at once (avoids N refresh() queries)
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
     * Delete a related entity from a many-to-many relationship.
     *
     * When `mutation.deleteRelated` is true (default), the related model is
     * deleted after detaching.  When false, only the pivot row is removed.
     */
    public function deleteRelation(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        $config        = $this->resolveRelationConfig($request->get('_relation'));
        $bulk          = $this->isBulkScenario($request);
        $relationName  = $request->get('_relation');
        $deleteRelated = $config['mutation']['deleteRelated'] ?? true;

        [$parent, $relatedId] = $this->resolveMutationParentAndRelatedId(
            $config, $bulk, $parentIdOrRelatedId, $relatedId
        );

        $data = $this->extractMutationData($request, $config);

        return $this->executeMutation($request, function () use ($parent, $config, $data, $bulk, $relatedId, $relationName, $deleteRelated) {
            $relationship = $parent->{$config['relationship']}();

            if ($bulk) {
                // Resolve IDs: $data may be a flat list [23,33] or keyed {"address_ids":[23,33]}
                $relatedKeyPlural = $config['relatedKey'] . 's';
                if (isset($data[$relatedKeyPlural])) {
                    $ids = $data[$relatedKeyPlural];
                } elseif (array_is_list($data)) {
                    $ids = $data;
                } else {
                    $ids = collect($data)->flatten()->all();
                }

                // 1 query: load all related entities at once (avoids N find() queries)
                $relatedEntities = $relationship->find($ids);
                $foundIds        = $relatedEntities->pluck('id')->all();
                $notFoundIds     = array_values(array_diff($ids, $foundIds));

                if (!empty($foundIds)) {
                    $relationship->detach($foundIds);                              // 1 query: batch detach
                    if ($deleteRelated) {
                        $config['relatedModel']::whereIn('id', $foundIds)->delete(); // 1 query: batch delete
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

            $relationship->detach($relatedId);
            if ($deleteRelated) {
                $related->delete();
            }

            // No llamar refresh() aqui: si deleteRelated=true el registro ya no existe
            return ['success' => true, 'model' => $related];
        });
    }

    /**
     * Attach existing related entities (pivot-only operation).
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
        $parent = $this->resolveParentEntity($config, $parentId);
        $data = $this->extractMutationData($request, $config);
        $scenario = $request->get('_scenario', 'attach');
        $relatedKey = $config['relatedKey'];

        return $this->executeMutation($request, function () use ($parent, $config, $data, $scenario, $relatedKey) {
            $relationship = $parent->{$config['relationship']}();

            return match (true) {
                str_contains($scenario, 'sync') => $relationship->sync($data),
                str_contains($scenario, 'toggle') => $relationship->toggle($data),
                str_contains($scenario, 'bulk') => $this->processBulkAttach($relationship, $data, $relatedKey),
                default => $this->processSingleAttach($relationship, $data, $relatedKey),
            };
        });
    }

    /**
     * Detach related entities (pivot-only removal, no model deletion).
     *
     * Scenarios:
     *   detach      → single ID
     *   bulk_detach → multiple IDs
     */
    public function detachRelation(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        $config = $this->resolveRelationConfig($request->get('_relation'));
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
     * Update pivot table fields only (without modifying the related model).
     *
     * Scenarios:
     *   update_pivot      → single related ID
     *   bulk_update_pivot → multiple related IDs
     */
    public function updatePivotRelation(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        $config = $this->resolveRelationConfig($request->get('_relation'));
        $bulk = $this->isBulkScenario($request);
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
    //  Mutation helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Resolve parent entity and relatedId for mutation operations.
     *
     * Handles all route shapes:
     *   - Bulk (any channel):     relatedId is inside the body, parentId may be in route or null (auth)
     *   - Frontend/Mobile single: parentIdOrRelatedId IS the relatedId, parent from auth
     *   - Admin single:           parentIdOrRelatedId IS parentId, relatedId from second param
     */
    protected function resolveMutationParentAndRelatedId(
        array $config,
        bool  $bulk,
        mixed $parentIdOrRelatedId,
        mixed $relatedId
    ): array
    {
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
     *
     * The closure may return either:
     *  - An array → wrapped in response()->json($data, $status)
     *  - A JsonResponse directly → returned as-is (used for predictable not-found outcomes
     *    that must carry HTTP 404 without going through the exception handler)
     *
     * @param  int  $status  HTTP status code when the closure returns an array (default 200).
     *                       Pass 201 for createRelation to comply with REST semantics.
     */
    protected function executeMutation(Request $request, callable $operation, int $status = 200): JsonResponse
    {
        DB::beginTransaction();
        try {
            $result = $operation();
            DB::commit();

            // Closure handled its own status (e.g., 404 not-found): propagate transparently.
            if ($result instanceof JsonResponse) {
                return $result;
            }

            return response()->json($result, $status);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('M2M mutation failed on ' . ($request->get('_relation') ?? 'unknown') . ' relation', [
                'controller' => static::class,
                'scenario' => $request->get('_scenario'),
                'exception' => $e,
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

    /**
     * Attach a single related entity with optional pivot data.
     */
    private function processSingleAttach(BelongsToMany $relationship, array $data, string $relatedKey): array
    {
        $id = $data[$relatedKey] ?? $data['id'] ?? null;
        $pivotData = $data;
        unset($pivotData[$relatedKey], $pivotData['id']);

        $relationship->attach($id, $pivotData);
        return ['attached' => [$id]];
    }

    /**
     * Attach multiple related entities with optional pivot data.
     */
    private function processBulkAttach(BelongsToMany $relationship, array $data, string $relatedKey): array
    {
        $attachData = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $id = $item[$relatedKey] ?? $item['id'] ?? null;
                $pivotData = $item;
                unset($pivotData[$relatedKey], $pivotData['id']);
                $attachData[$id] = $pivotData;
            } else {
                $attachData[] = $item;
            }
        }

        $relationship->attach($attachData);
        return ['attached' => array_keys($attachData)];
    }

    // ──────────────────────────────────────────────────────────────
    //  Resolution helpers
    // ──────────────────────────────────────────────────────────────

    protected function resolveRelationConfig(?string $relationName): array
    {
        if (!$relationName || !isset($this->manyToManyConfig[$relationName])) {
            throw new BadRequestHttpException(
                "Many-to-many relation '{$relationName}' is not configured on " . static::class
            );
        }

        return $this->manyToManyConfig[$relationName];
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

    // ──────────────────────────────────────────────────────────────
    //  Request parsing
    // ──────────────────────────────────────────────────────────────

    protected function parseManyToManyParams(Request $request): array
    {
        return [
            'eq' => $this->parseManyToManyJsonParam($request, 'eq', 'attr'),
            'oper' => $this->parseManyToManyJsonParam($request, 'oper'),
            'orderby' => $this->parseManyToManyJsonParam($request, 'orderby'),
            'pagination' => $this->parseManyToManyJsonParam($request, 'pagination'),
            'select' => $this->parseManyToManySelect($request),
            'relations' => $this->parseManyToManyRelations($request),
        ];
    }

    /**
     * Decode a JSON query parameter. Optionally merge a second alias key (eq + attr).
     */
    private function parseManyToManyJsonParam(Request $request, string $key, ?string $alias = null): array
    {
        $value = $request->get($key);
        $decoded = is_string($value) ? (json_decode($value, true) ?? []) : ($value ?? []);

        if ($alias) {
            $aliasValue = $request->get($alias);
            $aliasDecoded = is_string($aliasValue) ? (json_decode($aliasValue, true) ?? []) : ($aliasValue ?? []);
            $decoded = array_merge($decoded, $aliasDecoded);
        }

        return $decoded;
    }

    private function parseManyToManySelect(Request $request): array
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

    private function parseManyToManyRelations(Request $request): array
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
    protected function applyManyToManyEqFilters(BelongsToMany $query, array $eq): void
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
    protected function applyManyToManyOperFilters(BelongsToMany $query, array $oper): void
    {
        if (empty($oper)) {
            return;
        }

        if (isset($oper['and'])) {
            $query->where(function ($q) use ($oper) {
                foreach ($oper['and'] as $condition) {
                    $this->applyManyToManySingleCondition($q, $condition, 'and');
                }
            });
        }

        if (isset($oper['or'])) {
            $query->where(function ($q) use ($oper) {
                foreach ($oper['or'] as $condition) {
                    $this->applyManyToManySingleCondition($q, $condition, 'or');
                }
            });
        }
    }

    /**
     * Parse a single condition string: "field operator value"
     *
     * Supported operators: =, !=, <, >, <=, >=, like, not like, ilike, not ilike,
     * in, not in, between, not between, null, not null, date, not date
     */
    protected function applyManyToManySingleCondition($query, string $condition, string $boolean): void
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
            $field = trim($m[1]);
            $op = strtolower(trim($m[2]));
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
            $field = trim($m[1]);
            $op = strtolower(trim($m[2]));
            $rawValues = trim($m[3], '[] ');
            $values = array_map('trim', explode(',', $rawValues));

            if ($op === 'in') {
                $query->whereIn($field, $values, $boolean);
            } else {
                $query->whereNotIn($field, $values, $boolean);
            }
            return;
        }

        // Handle comparison and pattern operators
        // Order matters: check multi-word operators first
        $operators = ['not ilike', 'not like', 'ilike', 'like', '!=', '>=', '<=', '>', '<', '='];

        foreach ($operators as $op) {
            $pos = stripos($condition, " {$op} ");
            if ($pos !== false) {
                $field = trim(substr($condition, 0, $pos));
                $value = trim(substr($condition, $pos + strlen($op) + 2));

                if (in_array(strtolower($op), ['like', 'not like'])) {
                    $value = str_contains($value, '%') ? $value : "%{$value}%";
                    $method = $boolean === 'or' ? 'orWhere' : 'where';
                    $query->{$method}($field, $op, $value);
                } elseif (in_array(strtolower($op), ['ilike', 'not ilike'])) {
                    $value = str_contains($value, '%') ? $value : "%{$value}%";
                    $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
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
    protected function applyManyToManyOrdering(BelongsToMany $query, array $orderby): void
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

    /**
     * Check if infinity (non-paginated) mode is requested.
     */
    private function isInfinityPagination(array $pagination): bool
    {
        return !empty($pagination['infinity']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Not-found error builders
    // ──────────────────────────────────────────────────────────────

    /**
     * Build a structured not-found error envelope for a single resource.
     *
     * Used in showRelation, updateRelation(single), deleteRelation(single).
     * The caller wraps this in response()->json($envelope, 404).
     *
     * Shape:
     *   {
     *     "success": false,
     *     "error": {
     *       "message":       "Address with id 99 not found in relation 'addresses'",
     *       "relation":      "addresses",
     *       "id":            99,
     *       "suggested_fix": "..."
     *     }
     *   }
     */
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

    /**
     * Build a structured not-found error object for bulk operations where
     * one or more items could not be located.
     *
     * Used as $results['error'] inside bulk mutations.
     * The parent array already carries success=false and models=[...found ones].
     *
     * Shape of the returned array (goes into $results['error']):
     *   {
     *     "message":       "2 Address record(s) not found in relation 'addresses': [99, 100]",
     *     "relation":      "addresses",
     *     "not_found_ids": [99, 100],
     *     "suggested_fix": "..."
     *   }
     */
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
