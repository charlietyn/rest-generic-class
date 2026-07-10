<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Ronu\RestGenericClass\Core\Services\Support\PivotMutationCoordinator;
use Ronu\RestGenericClass\Core\Services\Support\RelationMutationCoordinator;
use Ronu\RestGenericClass\Core\Services\Support\RelationQueryFilter;
use Ronu\RestGenericClass\Core\Services\Support\RelationReadCoordinator;
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
    use HasDynamicOrderBy;

    private function relationReadCoordinator(): RelationReadCoordinator
    {
        return new RelationReadCoordinator(
            fn (?string $relationName): array => $this->resolveRelationConfig($relationName),
            fn (array $config, mixed $parentId): mixed => $this->resolveParentEntity($config, $parentId),
            $this->relationQueryFilter(),
            fn (string $modelName, mixed $id, string $relation): array => $this->buildNotFoundError($modelName, $id, $relation),
            fn (): int => $this->modelClass->getPerPage()
        );
    }

    private function relationMutationCoordinator(): RelationMutationCoordinator
    {
        return new RelationMutationCoordinator(
            fn (?string $relationName): array => $this->resolveRelationConfig($relationName),
            fn (array $config, mixed $parentId): mixed => $this->resolveParentEntity($config, $parentId),
            fn (array $config, bool $bulk, mixed $parentIdOrRelatedId, mixed $relatedId): array => $this->resolveMutationParentAndRelatedId($config, $bulk, $parentIdOrRelatedId, $relatedId),
            fn (Request $request, array $config): array => $this->extractMutationData($request, $config),
            fn (Request $request, callable $operation, int $status = 200): JsonResponse => $this->executeMutation($request, $operation, $status),
            fn (Request $request): bool => $this->isBulkScenario($request),
            fn (string $modelName, mixed $id, string $relation): array => $this->buildNotFoundError($modelName, $id, $relation),
            fn (string $modelName, array $notFoundIds, string $relation): array => $this->buildBulkNotFoundError($modelName, $notFoundIds, $relation)
        );
    }

    private function relationQueryFilter(): RelationQueryFilter
    {
        return new RelationQueryFilter();
    }

    private function pivotMutationCoordinator(): PivotMutationCoordinator
    {
        return new PivotMutationCoordinator(
            fn (?string $relationName): array => $this->resolveRelationConfig($relationName),
            function (array $config, string $method): void {
                $this->assertManyToMany($config, $method);
            },
            fn (array $config, mixed $parentId): mixed => $this->resolveParentEntity($config, $parentId),
            fn (array $config, bool $bulk, mixed $parentIdOrRelatedId, mixed $relatedId): array => $this->resolveMutationParentAndRelatedId($config, $bulk, $parentIdOrRelatedId, $relatedId),
            fn (Request $request, array $config): array => $this->extractMutationData($request, $config),
            fn (Request $request, callable $operation, int $status = 200): JsonResponse => $this->executeMutation($request, $operation, $status),
            fn (Request $request): bool => $this->isBulkScenario($request)
        );
    }

    //  Read entry-points
    // --------------------------------------------------------------

    public function listRelation(Request $request, mixed $parentId = null): LengthAwarePaginator|array
    {
        return $this->relationReadCoordinator()->list($request, $parentId);
    }

    public function showRelation(Request $request, mixed $parentIdOrRelatedId, mixed $relatedId = null): mixed
    {
        return $this->relationReadCoordinator()->show($request, $parentIdOrRelatedId, $relatedId);
    }

    public function exportRelationExcel(Request $request, mixed $parentId = null): mixed
    {
        return $this->relationReadCoordinator()->exportExcel($request, $parentId);
    }

    public function exportRelationPdf(Request $request, mixed $parentId = null): mixed
    {
        return $this->relationReadCoordinator()->exportPdf($request, $parentId);
    }

    //  Mutation entry-points
    // --------------------------------------------------------------

    public function createRelation(Request $request, mixed $parentId = null): JsonResponse
    {
        return $this->relationMutationCoordinator()->create($request, $parentId);
    }

    public function updateRelation(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        return $this->relationMutationCoordinator()->update($request, $parentIdOrRelatedId, $relatedId);
    }

    public function deleteRelation(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        return $this->relationMutationCoordinator()->delete($request, $parentIdOrRelatedId, $relatedId);
    }

    public function attachRelation(Request $request, mixed $parentId = null): JsonResponse
    {
        return $this->pivotMutationCoordinator()->attach($request, $parentId);
    }

    public function detachRelation(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        return $this->pivotMutationCoordinator()->detach($request, $parentIdOrRelatedId, $relatedId);
    }

    public function updatePivotRelation(Request $request, mixed $parentIdOrRelatedId = null, mixed $relatedId = null): JsonResponse
    {
        return $this->pivotMutationCoordinator()->updatePivot($request, $parentIdOrRelatedId, $relatedId);
    }

    public function processRelationPagination($params, $query): mixed
    {
        return $this->relationReadCoordinator()->processPagination($params, $query);
    }

    public function process_pagination($params, $query): mixed
    {
        return $this->processRelationPagination($params, $query);
    }


    //  Resolution helpers
    // --------------------------------------------------------------

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

    // --------------------------------------------------------------
    //  Request parsing
    // --------------------------------------------------------------

    protected function parseRelationParams(Request $request): array
    {
        return $this->relationReadCoordinator()->parseParams($request);
    }

    // --------------------------------------------------------------
    //  Query application
    // --------------------------------------------------------------

    /**
     * Apply equality filters: { "field": "value" } or { "field": [1,2,3] }
     */
    protected function applyEqFilters(Relation $query, array $eq): void
    {
        $this->relationQueryFilter()->applyEq($query, $eq);
    }

    /**
     * Apply complex oper filters.
     *
     * Supports format:
     *   { "and": ["field operator value", ...], "or": ["field operator value", ...] }
     */
    protected function applyOperFilters(Relation $query, array $oper): void
    {
        $this->relationQueryFilter()->applyOper($query, $oper);
    }

    /**
     * Parse a single condition string: "field operator value"
     *
     * Supported operators: =, !=, <, >, <=, >=, like, not like, ilike, not ilike,
     * in, not in, between, not between, null, not null
     */
    protected function applySingleCondition($query, string $condition, string $boolean): void
    {
        $this->relationQueryFilter()->applySingleCondition($query, $condition, $boolean);
    }

    /**
     * Apply ordering: [{"field":"asc"}, {"field2":"desc"}]
     *
     * Supports dot notation for related-entity fields (e.g. "user.name") on
     * the related model of the relationship being listed. Delegates to the
     * HasDynamicOrderBy trait so behavior matches the main listing endpoint.
     */
    protected function applyOrdering(Relation $query, array $orderby): void
    {
        $this->relationQueryFilter()->applyOrdering($query, $orderby);
    }

    // --------------------------------------------------------------
    //  Export helpers
    // --------------------------------------------------------------

    // --------------------------------------------------------------
    //  Not-found error builders
    // --------------------------------------------------------------

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
