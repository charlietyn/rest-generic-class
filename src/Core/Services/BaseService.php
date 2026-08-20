<?php
/**Generate by ASGENS
 * @author Charlietyn
 */


namespace Ronu\RestGenericClass\Core\Services;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Nwidart\Modules\Facades\Module;
use Ronu\RestGenericClass\Core\Contracts\HasRestRelations;
use Ronu\RestGenericClass\Core\Services\Support\CacheCoordinator;
use Ronu\RestGenericClass\Core\Services\Support\ExportCoordinator;
use Ronu\RestGenericClass\Core\Services\Support\HierarchyCoordinator;
use Ronu\RestGenericClass\Core\Services\Support\OperFilterPipeline;
use Ronu\RestGenericClass\Core\Services\Support\PaginationCoordinator;
use Ronu\RestGenericClass\Core\Services\Support\QueryBuilderPipeline;
use Ronu\RestGenericClass\Core\Services\Support\RelationResolver;
use Ronu\RestGenericClass\Core\Traits\HasDynamicFilter;
use Ronu\RestGenericClass\Core\Traits\HasDynamicOrderBy;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @property Model $modelClass
 *
 * */
class BaseService
{
    use HasDynamicFilter;
    use HasDynamicOrderBy;

    /** @var BaseModel|string $modelClass */
    public string|BaseModel|Model $modelClass = '';

    /**
     * Prefijo base para las claves de caché del paquete.
     *
     * Nota para junior: este prefijo ayuda a separar nuestras claves
     * de otras claves del proyecto y facilita versionar la estrategia.
     */
    private string $cachePrefix = 'rgc:v2';

    /**
     * Per-service cache toggle.
     *
     * - null  → defers to the global config (REST_CACHE_ENABLED). Default.
     * - true  → forces cache ON for this service, even if global is off.
     * - false → forces cache OFF for this service, even if global is on.
     *
     * Override in child services:
     *   protected ?bool $cacheable = false; // never cache orders
     */
    protected ?bool $cacheable = null;

    /**
     * Per-service TTL override (seconds).
     *
     * When set, takes precedence over the global ttl and ttl_by_method config.
     * Request-level cache_ttl still overrides this value.
     *
     * Override in child services:
     *   protected ?int $cacheTtl = 300; // 5 minutes for products
     */
    protected ?int $cacheTtl = null;

    /**
     * Per-service cacheable operations override.
     *
     * When non-empty, only these operations are cached for this service.
     * When empty (default), defers to config cacheable_methods.
     *
     * Override in child services:
     *   protected array $cacheableOperations = ['list_all']; // only cache listings
     */
    protected array $cacheableOperations = [];

    /**
     * @var int Depth counter for recursive nested queries
     */
    private int $currentDepth = 0;

    /**
     * @var int Total condition counter across all levels
     */
    private int $conditionCount = 0;

    /**
     * Services constructor.
     * @param String|Model $modelClass
     */
    public function __construct(Model|string $modelClass)
    {
        $this->modelClass = new $modelClass;
    }

    private function cacheCoordinator(): CacheCoordinator
    {
        $model = $this->modelClass instanceof Model
            ? $this->modelClass
            : new $this->modelClass;

        return new CacheCoordinator(
            $model,
            $this->cachePrefix,
            $this->cacheable,
            $this->cacheTtl,
            $this->cacheableOperations,
            fn (array $params): array => $this->getRelationVersions($params)
        );
    }

    private function relationResolver(): RelationResolver
    {
        return new RelationResolver();
    }

    private function operFilterPipeline(): OperFilterPipeline
    {
        return new OperFilterPipeline(
            $this->relationResolver(),
            fn (Builder $query, array $params, string $condition, $modelClass): Builder => $this->applyFilters($query, $params, $condition, $modelClass),
            fn (): int => $this->currentDepth,
            fn (int $depth): int => $this->currentDepth = $depth,
            fn (int $count): int => $this->conditionCount += $count,
            fn (): int => $this->conditionCount
        );
    }

    private function queryBuilderPipeline(): QueryBuilderPipeline
    {
        $model = $this->modelClass instanceof Model
            ? $this->modelClass
            : new $this->modelClass;

        return new QueryBuilderPipeline(
            $model,
            fn (Builder $query, array|string $params): Builder => $this->eq_attr($query, $params),
            fn (Builder $query, mixed $oper, string $boolean = 'and', $modelClass = null): Builder => $this->applyOperTree($query, $oper, $boolean, $modelClass),
            fn (Builder $query, mixed $relations, mixed $oper = []): Builder => $this->relations($query, $relations, $oper),
            fn (Builder $query, array|string $params): Builder => $this->order_by($query, $params)
        );
    }

    private function paginationCoordinator(): PaginationCoordinator
    {
        $model = $this->modelClass instanceof Model
            ? $this->modelClass
            : new $this->modelClass;

        return new PaginationCoordinator($model);
    }

    private function exportCoordinator(): ExportCoordinator
    {
        $model = $this->modelClass instanceof Model
            ? $this->modelClass
            : new $this->modelClass;

        return new ExportCoordinator(
            $model,
            fn ($params): mixed => $this->list_all($params)
        );
    }

    private function hierarchyCoordinator(): HierarchyCoordinator
    {
        $model = $this->modelClass instanceof Model
            ? $this->modelClass
            : new $this->modelClass;

        return new HierarchyCoordinator(
            $model,
            fn (array $params, Builder $query): Builder => $this->process_query($params, $query),
            fn (Builder $query, mixed $relations, mixed $oper = null): Builder => $this->relations($query, $relations, $oper),
            fn (array $params, bool $toJson = true): mixed => $this->list_all($params, $toJson),
            fn (array $params, mixed $id): mixed => $this->show($params, $id)
        );
    }

    private function pagination($query, $pagination): LengthAwarePaginator
    {
        return $this->paginationCoordinator()->paginate($query, $pagination);
    }

    /***
     * Get the static class name of the model.
     *
     * @return string The static class name of the model.
     */
    private function getStaticClass(): string
    {
        $instance = $this->modelClass;
        $class = $instance::class;
        return get_class($instance);
    }

    /***
     * Get the relations defined in the model class.
     *
     * @return array The relations defined in the model class.
     */
    private function getModelRelations(): array
    {
        if ($this->modelClass instanceof HasRestRelations) {
            return $this->modelClass->getRestRelations();
        }

        $staticClass = $this->getStaticClass();
        return defined($staticClass . '::RELATIONS') ? $staticClass::RELATIONS : [];
    }

    /**
     * Apply eager loading with optional field selection and filters.
     *
     * @param Builder $query
     * @param mixed $params Relations parameter (can include field selection)
     * @param mixed|array $oper Filters to apply to eager loaded relations (if _nested=true)
     * @return Builder
     */
    private function relations($query, $params, $oper = []): Builder
    {
        /**@var Builder $query * */
        $normalizedRelations = $this->normalizeRelations($params);

        if (empty($normalizedRelations)) {
            return $query;
        }

        // Process nested relations with fields
        $processed = $this->processNestedRelationsWithFields($normalizedRelations);

        // Validate all requested relations
        $allowedRelations = $this->getRelationsForModel($this->modelClass);
        foreach ($processed as $parsed) {
            $baseRelation = $parsed['base'];

            if (!in_array($baseRelation, $allowedRelations, true)) {
                throw new HttpException(
                    400,
                    "Relation '{$baseRelation}' is not allowed. Allowed: " . implode(', ', $allowedRelations)
                );
            }
        }

        // Extract relation-specific filters
        $normalized = $this->normalizeOperNode($oper);
        $relationFilters = !empty($normalized)
            ? $this->extractRelationFiltersForModel($normalized, $this->modelClass)
            : [];

        $with = [];

        foreach ($processed as $parsed) {
            $relation = $parsed['relation'];
            $fields = $parsed['fields'];
            $baseRelation = $parsed['base'];

            // For nested relations with fields, use the pre-computed key
            $withKey = isset($parsed['key']) ? $parsed['key'] : $relation;

            // If simple relation with fields
            if (!isset($parsed['key']) && $fields) {
                $fieldsWithKeys = $this->ensureForeignKeysInFields(
                    $this->modelClass,
                    $baseRelation,
                    $fields
                );
                $withKey = $relation . ':' . implode(',', $fieldsWithKeys);
            }

            // Check if there are filters for this relation
            $hasFilters = array_key_exists($relation, $relationFilters) ||
                array_key_exists($baseRelation, $relationFilters);

            if ($hasFilters) {
                $filters = $relationFilters[$relation] ?? $relationFilters[$baseRelation];
                $relatedModel = $this->getRelatedModel($this->modelClass, $baseRelation);

                $with[$withKey] = function ($relationQuery) use ($filters, $relatedModel) {
                    $this->applyOperTree($relationQuery, $filters, 'and', $relatedModel);
                };
            } else {
                $with[] = $withKey;
            }
        }

        return $query->with($with);
    }

    /**
     * Normalize relations parameter and extract field selections.
     *
     * @param mixed $relations
     * @return array [
     *   ['relation' => 'user', 'fields' => ['id','name']],
     *   ['relation' => 'roles', 'fields' => null],
     *   ...
     * ]
     */
    private function normalizeRelations(mixed $relations): array
    {
        return $this->relationResolver()->normalize($relations, $this->modelClass);
    }

    /**
     * Extract relation-specific filters from oper node.
     * @param $oper
     * @return array
     */
    private function extractRelationFilters($oper): array
    {
        if (!$oper || !is_array($oper)) {
            return [];
        }
        $filters = [];
        foreach ($oper as $key => $value) {
            if (is_string($key) && !in_array($key, ['and', 'or'], true)) {
                $filters[$key] = $value;
            }
        }
        return $filters;
    }


    /**
     * Add equality conditions to the query.
     *
     * If the parameter is a string, it will be json decoded.
     *
     * If the value of the parameter is an array, it will be added as a whereIn condition.
     * Otherwise, it will be added as a where condition.
     *
     * @param Builder $query
     * @param array|string $params
     * @return Builder
     */
    private function eq_attr(Builder $query, array|string $params): Builder
    {
        if (is_string($params)) {
            $params = json_decode($params);
        }
        foreach ($params as $index => $parameter) {
            if (is_array($parameter)) {
                $query = $query->whereIn($index, $parameter);
            } else
                $query = $query->where([$index => $parameter]);
        }
        return $query;
    }


    /**
     * Apply ordering to the query based on given parameters.
     *
     * Supports dot notation for related-entity fields (e.g. "user.name",
     * "user.role.name"). Relation segments are validated against the model's
     * const RELATIONS whitelist and translated to scalar ordering subqueries
     * via the HasDynamicOrderBy trait — no manual JOINs are added, so result
     * sets are not duplicated for *-to-many relations.
     *
     * Local columns (no dot) are prefixed with the model's table name to
     * avoid ambiguity. Literal "table.column" entries where the first segment
     * is not a declared relation are passed through verbatim for backward
     * compatibility.
     *
     * @param Builder $query The query builder instance to apply the ordering on.
     * @param array|string $params The parameters for ordering, can be a JSON string
     *                             or an array of column-direction pairs.
     * @return Builder The query builder instance with applied ordering.
     */
    private function order_by(Builder $query, array|string $params): Builder
    {
        return $this->applyDynamicOrderBy($query, $params, $this->modelClass);
    }


    /**
     * Build a query with a given array of conditions.
     *
     * The first key of the array is a logical operator, either 'and' or 'or'.
     * The value associated with this key is an array of conditions.  Each condition
     * is a string in the format of `column_name operator value`.  For example,
     * `name = John Smith`.  The operator can be any of the following:
     *
     *  - `=`
     *  - `!=`
     *  - `<`
     *  - `>`
     *  - `<=`
     *  - `>=`
     *  - `like`
     *  - `not like`
     *  - `ilike`
     *  - `not ilike`
     *  - `in`
     *  - `not in`
     *  - `between`
     *  - `not between`
     *  - `date`
     *  - `not date`
     *  - `notdate`
     *  - `null`
     *  - `not null`
     *  - `exists`
     *  - `not exists`
     *  - `regexp`
     *  - `not regexp`
     *
     * The value can be a single value, or an array of values.
     *
     * @param Builder $query
     * @param array $params
     * @param string $condition
     * @return Builder
     */
    private function oper(Builder $query, array $params, string $condition = "and"): Builder
    {
        $allNumericKeys = array_keys($params) === array_filter(array_keys($params), 'is_int');
        if ($allNumericKeys) {
            $params = ['and' => $params];
        }
        return $this->applyFilters($query, $params, $condition);
    }

    /**
     *   * Process the query with given parameters.
     **/
    public function process_query($params, $query): Builder
    {
        $this->currentDepth = 0;
        $this->conditionCount = 0;

        return $this->queryBuilderPipeline()->process($params, $query);
    }

    private function stripRelationFilters($oper): mixed
    {
        if (!is_array($oper)) {
            return $oper;
        }
        $relations = $this->getModelRelations();
        foreach (array_keys($oper) as $key) {
            if (is_string($key) && !in_array($key, ['and', 'or'], true) && !in_array($key, $relations, true)) {
                unset($oper[$key]);
            }
        }
        return $oper;
    }

    public function list_all($params, $toJson = true): mixed
    {
        if ($toJson && $this->shouldUseCache('list_all', (array)$params)) {
            return $this->rememberWithCache('list_all', (array)$params, function () use ($params, $toJson) {
                return $this->listAllWithoutCache($params, $toJson);
            });
        }

        return $this->listAllWithoutCache($params, $toJson);
    }

    private function listAllWithoutCache($params, $toJson = true): mixed
    {
        // Check if hierarchy mode is requested
        if (isset($params['hierarchy']) && $params['hierarchy']) {
            return $this->listHierarchy($params, $toJson);
        }
        $query = $this->modelClass->query();
        $query = $this->process_all($params, $query);
        if ($query instanceof CursorPaginator)
            return $query;
        else
            return $query instanceof LengthAwarePaginator ? $query : ($toJson ? ['data' => $query->get()->jsonSerialize()] : $query->get()->toArray());
    }

    /**
     * Process the query with all parameters including pagination.
     * @param $params
     * @param $query
     * @return mixed
     */

    public function process_all($params, $query): mixed
    {
        $this->currentDepth = 0;
        $this->conditionCount = 0;

        return $this->queryBuilderPipeline()->processAll(
            $params,
            $query,
            fn ($params, Builder $query): mixed => $this->process_pagination($params, $query)
        );
    }

    /**
     * Process pagination for the query based on the given parameters.
     * @param $params
     * @param $query
     * @return mixed
     */
    public function process_pagination($params, $query): mixed
    {
        return $this->paginationCoordinator()->process($params, $query);
    }

    public function get_one($params, $toJson = true): mixed
    {
        if ($toJson && $this->shouldUseCache('get_one', (array)$params)) {
            return $this->rememberWithCache('get_one', (array)$params, function () use ($params, $toJson) {
                return $this->getOneWithoutCache($params, $toJson);
            });
        }

        return $this->getOneWithoutCache($params, $toJson);
    }

    private function getOneWithoutCache($params, $toJson = true): mixed
    {
        $query = $this->modelClass->query();
        $query = $this->process_query($params, $query);
        unset($params['pagination']);
        $value = $query->first();
        return $toJson ? ['data' => $value?->jsonSerialize()] : ($value?->toArray() ?? []);
    }

    public function get_parents($modelClass, $attributes = null, $scenario = 'create', $specific = false): array
    {
        $parent_array = [];
        if ($modelClass->hasHierarchy()) {
            $parent_class = $modelClass::PARENT['class'];
            $parent = new $parent_class();
            $parent->fill($attributes);
            $parent_validate = $parent->self_validate($scenario, $specific);
            if ($parent->hasHierarchy()) {
                $parent_array = $parent->get_parents($parent, $attributes, $scenario, $specific);
            }
            array_push($parent_array, $parent_validate);
        }
        return $parent_array;
    }


    public function save_parents($modelClass, $attributes = null, $scenario = 'create', $specific = false): array
    {
        $parent = null;
        if ($modelClass->hasHierarchy()) {
            $parent_class = $modelClass::PARENT['class'];
            if (!isset($attributes[$this->modelClass->getPrimaryKey()])) {
                $parent = new $parent_class();
            } else {
                $parent = $parent_class::find($attributes[$this->modelClass->getPrimaryKey()]);
            }
            $parent->fill($attributes);
            $parent->save();
            if ($parent->hasHierarchy()) {
                $parent->save_parents($parent, $attributes, $scenario, $specific);
            }
        }
        return $parent;
    }

    private function parents_validate($attributes, $scenario = null, $specific = false): array
    {
        $result = null;
        $parents = $this->get_parents($this->modelClass, $attributes, $scenario, $specific);
        foreach ($parents as $p) {
            if (count($p['errors']) > 0) {
                $result['success'] = false;
                $result['errors'] = $p['errors'];
                $result['model'] = $p['model'];
            }
        }
        return $result;
    }

    public function validate_all(array $attributes, $scenario = 'create', $specific = false): array
    {
        $validate = [];
        if (isset($attributes[$this->modelClass->getPrimaryKey()]) && $scenario != 'create')
            $scenario = "update";
        $this->modelClass->setScenario($scenario);
        if (count($this->modelClass::PARENT) > 0) {
            $parent_class = $this->modelClass::PARENT['class'];
            if (!isset($attributes[$this->modelClass->getPrimaryKey()])) {
                $parent = new $parent_class();
            } else {
                $parent = $parent_class::find($attributes[$this->modelClass->getPrimaryKey()]);
            }
            if (!$parent) {
                $result = ["success" => false, 'error' => "Element not found", "model" => $parent_class];
                return $result;
            }
            $validateparents = $this->parents_validate($attributes, $this->modelClass->getScenario(), $specific);
            if ($validateparents)
                $validate[] = $validateparents;
        }
        $this->modelClass->fill($attributes);
        $valid = $this->modelClass->self_validate($this->modelClass->getScenario(), $specific, false);
        if ($valid['success'] && count($validate) == 0) {
            $result = ["success" => true, 'error' => []];
        } else {
            if (!$valid['success'])
                array_push($validate, $valid['errors']);
            $result = ["success" => false, "errors" => $validate[0], 'model' => $this->modelClass];
        }
        return $result;
    }

    public function save(array $attributes, $scenario = 'create', $validate = false): array
    {
        $parent = null;
        if (isset($attributes[$this->modelClass->getPrimaryKey()]) && $scenario != 'create') {
            $this->modelClass = $this->modelClass::find($attributes[$this->modelClass->getPrimaryKey()]);
            if ($this->modelClass)
                $this->modelClass->setScenario('update');
            else {
                return ["success" => false, "message" => 'Not Found elemnt with this primary Key'];
            }
        }
        $valid = $validate ? $this->validate_all($attributes, $this->modelClass->getScenario()) : ['success' => true];
        if (!$valid['success'])
            return $valid;
        if (count($this->modelClass::PARENT) > 0) {
            $parent = $this->save_parents($this->modelClass, $attributes, $this->modelClass->getScenario());
        }
        if ($parent)
            $attributes[$this->modelClass->getPrimaryKey()] = $parent[$this->modelClass->getPrimaryKey()];
        $this->modelClass = new $this->modelClass;
        $this->modelClass->fill($attributes);
        $this->modelClass->save();
        $result = ["success" => true, "model" => $this->modelClass->getAttributes()];
        return $result;
    }

    /**
     * @throws HttpException
     */
    public function create(array $params): array
    {
        if (isset($params[strtolower($this->modelClass::MODEL)]) || array_key_exists(0, $params)) {
            $params = $params[strtolower($this->modelClass::MODEL)] ?? $params;
            if (!$params)
                throw new HttpException(400, 'Bad Request:Params must be an array or object value');
            $result = $this->save_array($params);
        } else {
            $result = $this->save($params);
        }

        if (($result['success'] ?? false) === true) {
            $this->bumpCacheVersion();
        }

        return $result;
    }

    public function save_array(array $attributes, $scenario = 'create', $validate = false): array
    {
        $result = [];
        $result['success'] = true;
        foreach ($attributes as $index => $model) {
            $save = $this->save($model, $scenario);
            if (!$save['success']) {
                $result['success'] = false;
                $result['error'][] = [$save['errors'], $save['model']];
            } else {
                $result[] = $save;
            }
        }
        return $result;
    }

    public function update(array $attributes, $id, $validate = false): array
    {
        $query = $this->modelClass->query();
        $fieldKeyUpdate = $this->modelClass->getFieldKeyUpdate() ?? $this->modelClass->getPrimaryKey();
        $this->modelClass = $this->modelClass->getFieldKeyUpdate() ? $query->where([$fieldKeyUpdate => $id])->firstOrFail() : $query->findOrFail($id);
        $this->modelClass->setScenario("update");
        $specific = isset($attributes["_specific"]) ? $attributes["_specific"] : false;
        $this->modelClass->fill($attributes);
        $valid = $validate ? $this->modelClass->self_validate($this->modelClass->getScenario(), $specific) : ["success" => true];
        if ($valid['success']) {
            $this->modelClass->save();
            $this->bumpCacheVersion();
            $result = ["success" => true, "model" => $this->modelClass->jsonSerialize()];
        } else {
            $result = $valid;
        }
        return $result;
    }

    public function update_multiple(array $params, $validate = false): array
    {
        $result = [];
        $result['success'] = true;
        foreach ($params as $index => $item) {
            $id = $item[$this->modelClass->getPrimaryKey()];
            $res = $this->update($item, $id, $validate);
            $result["models"][] = $res;
            if (!$res['success'])
                $result['success'] = false;
        }
        return $result;
    }

    public function show($params, $id): mixed
    {
        // Check if hierarchy mode is requested
        if (isset($params['hierarchy']) && $params['hierarchy']) {
            return $this->showHierarchy($params, $id);
        }

        $nested = isset($params['_nested']) ? $params['_nested'] : false;
        $query = $this->modelClass->query();
        if (isset($params['relations'])) {
            $query = $this->relations($query, $params['relations'], $nested ? $params["oper"] : null);
        }
        if (isset($params['select'])) {
            $query = $query->select($params['select']);
        }
        return $query->findOrFail($id);
    }

    /**
     * Show a single record in hierarchical structure.
     *
     * @param array $params Query parameters including hierarchy config
     * @param mixed $id The record ID to show
     * @return array Hierarchical structure of the record
     * @throws HttpException If model doesn't support hierarchy
     */
    public function showHierarchy(array $params, mixed $id): array
    {
        return $this->hierarchyCoordinator()->show($params, $id);
    }

    public function destroy($id): array
    {
        $this->modelClass = $this->modelClass->query()->findOrFail($id);
        $result = [];
        $result['success'] = true;
        $result['model'] = $this->modelClass;
        // delete() is soft when the model uses InteractsWithSoftDelete (sets the
        // soft-delete column), physical otherwise — fully backward compatible.
        if (!$this->modelClass->delete())
            $result['success'] = false;
        if ($result['success']) {
            $this->bumpCacheVersion();
        }
        return $result;
    }

    public function destroybyid($id): array
    {
        $response = $this->modelClass::destroy($id);
        $result['success'] = $response > 0;
        if ($result['success']) {
            $this->bumpCacheVersion();
        }
        return $result;
    }

    /**
     * Restore a single soft-deleted record by id.
     *
     * Resolves the record WITH trashed rows (so the lookup does not 404 on an
     * already soft-deleted row) and restores it, firing the restoring/restored
     * events. Only valid for soft-deletable models; for non-soft models this is
     * a no-op reported as an error so callers can surface a clear 422.
     *
     * @param mixed $id
     * @return array{success: bool, ...}
     */
    public function restore($id): array
    {
        if (!$this->modelClass->isSoftDeletable()) {
            return ['success' => false, 'message' => 'Model is not soft-deletable; nothing to restore.'];
        }

        $this->modelClass = $this->modelClass->newQuery()->withTrashed()->findOrFail($id);
        $result = ['success' => true, 'model' => $this->modelClass];

        if (!$this->modelClass->trashed()) {
            // Already active — nothing to do, but report it explicitly.
            $result['message'] = 'Record is not deleted.';
            return $result;
        }

        if (!$this->modelClass->restore()) {
            $result['success'] = false;
            return $result;
        }

        $this->bumpCacheVersion();
        return $result;
    }

    /**
     * Restore multiple soft-deleted records by id.
     *
     * @param array|mixed $ids
     * @return array{success: bool, models: array}
     */
    public function restoreById($ids): array
    {
        $ids = is_array($ids) ? $ids : [$ids];
        $result = ['success' => true, 'models' => []];
        foreach ($ids as $id) {
            $res = $this->restore($id);
            $result['models'][] = $res;
            if (!$res['success']) {
                $result['success'] = false;
            }
        }
        return $result;
    }

    /**
     * Permanently delete a record by id, bypassing soft delete.
     *
     * For soft-deletable models the row is resolved WITH trashed rows so an
     * already soft-deleted record can be purged. For non-soft models this is
     * equivalent to a normal physical delete.
     *
     * @param mixed $id
     * @return array{success: bool, ...}
     */
    public function forceDelete($id): array
    {
        $query = $this->modelClass->newQuery();
        if ($this->modelClass->isSoftDeletable()) {
            $query->withTrashed();
        }
        $this->modelClass = $query->findOrFail($id);
        $result = ['success' => true, 'model' => $this->modelClass];

        if (!$this->modelClass->forceDelete()) {
            $result['success'] = false;
            return $result;
        }

        $this->bumpCacheVersion();
        return $result;
    }

    /**
     * Permanently delete multiple records by id.
     *
     * @param array|mixed $ids
     * @return array{success: bool, models: array}
     */
    public function forceDeleteById($ids): array
    {
        $ids = is_array($ids) ? $ids : [$ids];
        $result = ['success' => true, 'models' => []];
        foreach ($ids as $id) {
            $res = $this->forceDelete($id);
            $result['models'][] = $res;
            if (!$res['success']) {
                $result['success'] = false;
            }
        }
        return $result;
    }


    /**
     * Decide si la operación actual debe usar caché.
     *
     * Reglas:
     * 1) Debe estar habilitado globalmente en config.
     * 2) El método debe estar permitido en cacheable_methods.
     * 3) El request puede desactivar caché con cache=false.
     */
    private function shouldUseCache(string $operation, array $params): bool
    {
        return $this->cacheCoordinator()->shouldUse($operation, $params);
    }

    /**
     * Recupera de caché o calcula y guarda el resultado.
     */
    private function rememberWithCache(string $operation, array $params, callable $callback): mixed
    {
        return $this->cacheCoordinator()->remember($operation, $params, $callback);
    }

    /**
     * Construye una clave determinística basada en el request.
     *
     * Importante: si cambia cualquier dimensión relevante (filtros, relaciones,
     * paginación, usuario, tenant, headers configurados), cambia la clave.
     */
    private function buildCacheKey(string $operation, array $params): string
    {
        return $this->cacheCoordinator()->key($operation, $params);
    }

    /**
     * Collects cache versions for all eagerly loaded relations in this request.
     *
     * When relations are requested (e.g., relations=["roles", "roles.permissions"]),
     * this method resolves each relation's model and fetches its cache version.
     * Including these versions in the cache key fingerprint ensures automatic
     * invalidation when a related model is written — without requiring manual
     * CACHE_INVALIDATES declarations.
     *
     * Supports nested dot-notation relations by walking the Eloquent chain.
     *
     * @param array $params Request parameters (must contain 'relations' key)
     * @return array<string, int> Map of relation path → cache version
     */
    private function getRelationVersions(array $params): array
    {
        $versions = [];
        if (!isset($params['relations'])) {
            return $versions;
        }

        $normalized = $this->normalizeRelations($params['relations']);
        $store = $this->resolveCacheStore();

        foreach ($normalized as $rel) {
            // Use the parsed relation path from normalizeRelations()
            $relationPath = $rel['relation'];

            // Resolve each segment: "roles.permissions" → ["roles", "permissions"]
            $segments = $rel['segments'];
            $currentModel = $this->modelClass;

            foreach ($segments as $i => $segment) {
                try {
                    // getRelatedModel() returns FQCN string, not an instance
                    $relatedClass = $this->getRelatedModel($currentModel, $segment);
                    $segmentPath = implode('.', array_slice($segments, 0, $i + 1));
                    $relKey = $this->cachePrefix . ':version:' . $relatedClass;
                    $versions[$segmentPath] = (int)$store->get($relKey, 1);
                    $currentModel = $relatedClass;
                } catch (\Throwable $e) {
                    break;
                }
            }
        }

        return $versions;
    }

    /**
     * Resuelve el store de Laravel Cache configurado para el paquete.
     * Puede ser redis, database, file, memcached, etc.
     */
    private function resolveCacheStore()
    {
        return $this->cacheCoordinator()->store();
    }

    /**
     * Determina el TTL efectivo.
     * Prioridad: cache_ttl en request > ttl_by_method > ttl global.
     */
    private function resolveCacheTtl(string $operation, array $params)
    {
        return $this->cacheCoordinator()->ttl($operation, $params);
    }

    /**
     * Obtiene la versión de caché por modelo.
     *
     * Esta versión se usa para invalidación lógica sin borrar claves una a una.
     */
    private function getCacheVersion(): int
    {
        return $this->cacheCoordinator()->modelVersion();
    }

    /**
     * Incrementa la versión de caché del modelo después de escrituras exitosas.
     */
    private function bumpCacheVersion(): void
    {
        $this->cacheCoordinator()->bumpVersion();
    }

    /**
     * Clave donde se guarda la versión de caché por modelo.
     */
    private function cacheVersionKey(): string
    {
        return $this->cacheCoordinator()->versionKey();
    }

    public function exportExcel($params)
    {
        return $this->exportCoordinator()->exportExcel($params);
    }

    public function exportPdf($params)
    {
        return $this->exportCoordinator()->exportPdf($params);
    }

    private function extractExportData(mixed $result): array
    {
        return $this->exportCoordinator()->extractData($result);
    }

    private function resolveExportColumns(array $params): array
    {
        return $this->exportCoordinator()->resolveColumns($params);
    }

    private function normalizeExportColumns(mixed $columns): array
    {
        return $this->exportCoordinator()->normalizeColumns($columns);
    }

    public static function sendEmail($view, $variables, $from, $name, $email, $subject): array
    {
        $result = [];
        try {
            Mail::send($view, $variables, function ($m) use ($email, $subject, $from, $name) {
                $m->to($email, "No-Reply");
                $m->from($from, $name);
                $m->subject($subject);
            });
            $result = ['success' => true];
        } catch (\Exception $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }
        return $result;
    }

    /**
     * Normalize oper node to standardized format: { "and"|"or": [...], relation: {...} }
     *
     * @param mixed $oper
     * @return array Normalized structure
     */
    private function normalizeOperNode(mixed $oper): array
    {
        return $this->operFilterPipeline()->normalize($oper);
    }

    /**
     * Get allowed relations for a given model class.
     *
     * @param object|string $modelClass
     * @return array List of allowed relation names
     * @throws HttpException if strict mode and no RELATIONS defined
     */
    private function getRelationsForModel(object|string $modelClass): array
    {
        return $this->relationResolver()->allowedFor($modelClass);
    }

    /**
     * Auto-detect relations via reflection (fallback, not recommended for production).
     */
    private function autoDetectRelations($model): array
    {
        return $this->relationResolver()->autoDetect($model);
    }

    /**
     * Extract relation filters from normalized oper node.
     * Only returns keys that are valid relations for the model.
     *
     * @param array $normalized Normalized oper structure
     * @param object|string $modelClass
     * @return array [ 'relation' => subOper, ... ]
     */
    private function extractRelationFiltersForModel(array $normalized, object|string $modelClass): array
    {
        return $this->relationResolver()->extractFilters($normalized, $modelClass);
    }

    /**
     * Remove relation filters from oper, keeping only 'and'/'or' conditions.
     *
     * @param array $normalized Normalized oper structure
     * @param object|string $modelClass
     * @return array Cleaned oper with only base conditions
     */
    private function stripRelationFiltersForModel(array $normalized, object|string $modelClass): array
    {
        return $this->relationResolver()->stripFilters($normalized);
    }

    /**
     * Apply complete oper tree: base conditions + nested whereHas.
     *
     * @param Builder $query
     * @param mixed $oper Raw oper structure
     * @param string $boolean 'and' | 'or' for top-level wrapping
     * @param string|object|null $modelClass Current model (for relation validation)
     * @return Builder
     */
    private function applyOperTree(Builder $query, mixed $oper, string $boolean = 'and', $modelClass = null): Builder
    {
        return $this->operFilterPipeline()->apply($query, $oper, $boolean, $modelClass, $this->modelClass);
    }

    /**
     * Apply whereHas with nested relation path (supports dot notation).
     *
     * @param Builder $query
     * @param string $relationPath e.g. 'user', 'user.roles'
     * @param mixed $subOper Sub-oper to apply inside the whereHas
     * @param string $boolean 'and' | 'or'
     * @param string|object $currentModel Current model class
     * @return Builder
     */
    private function applyNestedWhereHas(
        Builder $query,
        string  $relationPath,
        mixed   $subOper,
        string  $boolean,
                $currentModel
    ): Builder
    {
        return $this->operFilterPipeline()->applyNestedWhereHas($query, $relationPath, $subOper, $boolean, $currentModel);
    }

    /**
     * Get the related model class for a given relation name.
     *
     * @param object|string $modelClass
     * @param string $relationName
     * @return string Related model class name
     * @throws HttpException if relation doesn't exist
     */
    private function getRelatedModel(object|string $modelClass, string $relationName): string
    {
        return $this->relationResolver()->relatedModel($modelClass, $relationName);
    }

    /**
     * Parse relation string with optional field selection.
     *
     * Examples:
     *   "user" → ['relation' => 'user', 'fields' => null]
     *   "user:id,name,email" → ['relation' => 'user', 'fields' => ['id','name','email']]
     *   "user.roles:id,name" → ['relation' => 'user.roles', 'fields' => ['id','name']]
     *
     * @param string $relationString
     * @return array ['relation' => string, 'fields' => array|null, 'segments' => array]
     */
    private function parseRelationWithFields(string $relationString): array
    {
        $parts = explode(':', $relationString, 2);
        $relation = trim($parts[0]);
        $fields = isset($parts[1]) ? array_map('trim', explode(',', $parts[1])) : null;

        // Parse segments for nested relations (e.g., "user.roles" → ["user", "roles"])
        $segments = explode('.', $relation);

        return [
            'relation' => $relation,
            'fields' => $fields,
            'segments' => $segments,
            'base' => $segments[0], // First segment (for validation)
        ];
    }

    /**
     * Ensure foreign keys are included in field selection for relations.
     * Laravel requires foreign keys when selecting specific fields.
     *
     * @param object|string $parentModel Parent model instance or class
     * @param string $relationName Relation method name
     * @param array $fields User-specified fields
     * @return array Fields with required foreign keys added
     */
    private function ensureForeignKeysInFields(object|string $parentModel, string $relationName, array $fields): array
    {
        return $this->relationResolver()->addRequiredFields($parentModel, $relationName, $fields);
    }

    /**
     * Handle nested relation with field selection (e.g., "user.roles:id,name")
     *
     * @param array $normalized Normalized relations array
     * @return array Processed for Laravel's with() method
     */
    private function processNestedRelationsWithFields(array $normalized): array
    {
        return $this->relationResolver()->processNestedWithFields($normalized);
    }

    /**
     * List all records in hierarchical (tree) structure.
     *
     * @param array $params Query parameters
     * @param bool $toJson Whether to return JSON serializable format
     * @return mixed Hierarchical data structure
     * @throws HttpException If model doesn't support hierarchy
     */
    public function listHierarchy(array $params, bool $toJson = true): mixed
    {
        return $this->hierarchyCoordinator()->list($params, $toJson);
    }
}
