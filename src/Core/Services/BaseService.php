<?php
/**Generate by ASGENS
 * @author Charlietyn
 */


namespace Ronu\RestGenericClass\Core\Services;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use Nwidart\Modules\Facades\Module;
use Ronu\RestGenericClass\Core\Contracts\HasRestRelations;
use Ronu\RestGenericClass\Core\Exports\ModelExport;
use Ronu\RestGenericClass\Core\Services\Support\CacheCoordinator;
use Ronu\RestGenericClass\Core\Services\Support\OperFilterPipeline;
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
    private string $cachePrefix = 'rgc:v1';

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


    private function pagination($query, $pagination): LengthAwarePaginator
    {
        if (is_string($pagination))
            $pagination = json_decode($pagination, true);
        $currentPage = isset($pagination["page"]) ? $pagination["page"] : 1;
        $pageSize = isset($pagination["pageSize"]) ? $pagination["pageSize"] : (isset($pagination["pagesize"]) ? $pagination["pagesize"] : null);
        Paginator::currentPageResolver(function () use ($currentPage) {
            return $currentPage;
        });
        return $query->paginate($pageSize);
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
        $pagination_lower = array_change_key_case($params['pagination']);
        $pagesize = array_key_exists('pagesize', $pagination_lower) ? $pagination_lower['pagesize'] : $this->modelClass->getPerPage();
        if (!isset($params['pagination']['infinity']) || $params['pagination']['infinity'] !== true)
            return $this->pagination($query, $params['pagination']);
        else {
            $cursor = isset($params['pagination']['cursor']) ? $params['pagination']['cursor'] : null;
            $items = $query->cursorPaginate($pagesize, ['*'], 'cursor', $cursor);
            return $items;
//            return [
//                'data' => $items->items(),
//                'next_cursor' => $items->nextCursor()?->encode(),
//                'has_more' => $items->hasMorePages(),
//            ];
        }
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
        // Validate model supports hierarchy
        if (!$this->modelClass->hasHierarchyField()) {
            throw new HttpException(400,
                "Model " . get_class($this->modelClass) . " does not support hierarchical show. " .
                "Define const HIERARCHY_FIELD_ID to enable this feature."
            );
        }

        // Normalize hierarchy parameters with show-specific defaults
        $hierarchyConfig = $this->normalizeShowHierarchyParams($params['hierarchy']);

        if ($hierarchyConfig === null) {
            // Hierarchy disabled, fallback to normal show
            unset($params['hierarchy']);
            return $this->show($params, $id)->toArray();
        }

        $hierarchyFieldId = $this->modelClass->getHierarchyFieldId();
        $primaryKey = $this->modelClass->getKeyName();
        $childrenKey = $hierarchyConfig['children_key'];
        $maxDepth = $hierarchyConfig['max_depth'];
        $mode = $hierarchyConfig['mode'];
        $includeEmptyChildren = $hierarchyConfig['include_empty_children'];

        // Get the requested record
        $nested = isset($params['_nested']) ? $params['_nested'] : false;
        $query = $this->modelClass->query();
        if (isset($params['relations'])) {
            $query = $this->relations($query, $params['relations'], $nested ? $params["oper"] : null);
        }
        if (isset($params['select'])) {
            $query = $query->select($params['select']);
        }
        $record = $query->findOrFail($id);

        // Build the hierarchical response based on mode
        $result = $this->buildShowHierarchy(
            $record,
            $mode,
            $hierarchyFieldId,
            $primaryKey,
            $childrenKey,
            $maxDepth,
            $includeEmptyChildren,
            $params
        );

        return $result;
    }

    /**
     * Normalize hierarchy parameters for show endpoint.
     *
     * @param mixed $hierarchy Raw hierarchy parameter
     * @return array|null Normalized config or null if disabled
     */
    private function normalizeShowHierarchyParams(mixed $hierarchy): ?array
    {
        $defaults = [
            'children_key' => 'children',
            'max_depth' => null,
            'mode' => 'with_descendants',  // Default for show is with_descendants
            'include_empty_children' => true,
        ];

        $validModes = ['node_only', 'with_descendants', 'with_ancestors', 'full_branch'];

        if ($hierarchy === null || $hierarchy === false) {
            return null;
        }

        // Simple boolean true - use defaults
        if ($hierarchy === true || $hierarchy === 'true' || $hierarchy === '1') {
            return $defaults;
        }

        // Parse JSON string if needed
        if (is_string($hierarchy)) {
            $decoded = json_decode($hierarchy, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $hierarchy = $decoded;
            } else {
                return null;
            }
        }

        if (!is_array($hierarchy)) {
            return null;
        }

        // Check for enabled flag
        if (isset($hierarchy['enabled']) && !$hierarchy['enabled']) {
            return null;
        }

        // Merge with defaults
        $config = array_merge($defaults, $hierarchy);

        // Validate mode
        if (!in_array($config['mode'], $validModes, true)) {
            throw new HttpException(400, "Invalid hierarchy mode '{$config['mode']}'. Valid modes for show: " . implode(', ', $validModes));
        }

        // Validate max_depth
        if ($config['max_depth'] !== null && (!is_int($config['max_depth']) || $config['max_depth'] < 1)) {
            throw new HttpException(400, "Hierarchy max_depth must be a positive integer or null.");
        }

        return $config;
    }

    /**
     * Build hierarchical structure for show endpoint.
     *
     * @param Model $record The main record
     * @param string $mode Hierarchy mode
     * @param string $hierarchyFieldId FK field name
     * @param string $primaryKey PK field name
     * @param string $childrenKey Children array key
     * @param int|null $maxDepth Maximum depth
     * @param bool $includeEmptyChildren Include empty children arrays
     * @param array $params Original query params (for relations/select)
     * @return array Hierarchical structure
     */
    private function buildShowHierarchy(
        $record,
        string $mode,
        string $hierarchyFieldId,
        string $primaryKey,
        string $childrenKey,
        ?int $maxDepth,
        bool $includeEmptyChildren,
        array $params
    ): array
    {
        $recordId = $record->{$primaryKey};

        switch ($mode) {
            case 'node_only':
                // Just the node with empty children
                $result = $record->toArray();
                if ($includeEmptyChildren) {
                    $result[$childrenKey] = [];
                }
                return $result;

            case 'with_descendants':
                // Node + all descendants as tree - optimized single query approach
                $allRecords = $this->loadDescendantsOptimized(
                    collect([$record]),
                    $hierarchyFieldId,
                    $primaryKey,
                    $params
                );

                $tree = $this->buildHierarchyTree(
                    $allRecords,
                    $hierarchyFieldId,
                    $primaryKey,
                    $childrenKey,
                    $maxDepth,
                    $includeEmptyChildren
                );

                // Find and return only the requested node with its descendants
                return $this->findNodeInTree($tree, $recordId, $primaryKey) ?? $record->toArray();

            case 'with_ancestors':
                // Build chain from root to this node - optimized
                $allRecords = $this->loadAncestorsOptimized(
                    collect([$record]),
                    $hierarchyFieldId,
                    $primaryKey,
                    $params
                );

                $tree = $this->buildHierarchyTree(
                    $allRecords,
                    $hierarchyFieldId,
                    $primaryKey,
                    $childrenKey,
                    $maxDepth,
                    $includeEmptyChildren
                );

                // Return the root (which contains the chain to our node)
                return $tree[0] ?? $record->toArray();

            case 'full_branch':
                // Ancestors + node + descendants - optimized
                $withAncestors = $this->loadAncestorsOptimized(
                    collect([$record]),
                    $hierarchyFieldId,
                    $primaryKey,
                    $params
                );

                $allRecords = $this->loadDescendantsOptimized(
                    $withAncestors,
                    $hierarchyFieldId,
                    $primaryKey,
                    $params
                );

                $tree = $this->buildHierarchyTree(
                    $allRecords,
                    $hierarchyFieldId,
                    $primaryKey,
                    $childrenKey,
                    $maxDepth,
                    $includeEmptyChildren
                );

                // Return the root of the branch
                return $tree[0] ?? $record->toArray();

            default:
                return $record->toArray();
        }
    }

    /**
     * Load descendants with optimized queries (loads full records, not just IDs).
     *
     * @param \Illuminate\Support\Collection $records Starting records
     * @param string $hierarchyFieldId FK field name
     * @param string $primaryKey PK field name
     * @param array $params Query params for relations/select
     * @return \Illuminate\Support\Collection All records including descendants
     */
    private function loadDescendantsOptimized(
        \Illuminate\Support\Collection $records,
        string                         $hierarchyFieldId,
        string                         $primaryKey,
        array                          $params
    ): \Illuminate\Support\Collection
    {
        $existingIds = $records->pluck($primaryKey)->toArray();
        $allDescendants = collect();

        // BFS to get all descendants - load full records in each iteration
        $currentIds = $existingIds;
        while (!empty($currentIds)) {
            $query = $this->modelClass->query()
                ->whereIn($hierarchyFieldId, $currentIds);

            // Apply relations if specified
            if (isset($params['relations'])) {
                $query = $this->relations($query, $params['relations'], null);
            }
            // Apply select if specified
            if (isset($params['select'])) {
                $query = $query->select($params['select']);
            }

            $children = $query->get();

            if ($children->isEmpty()) {
                break;
            }

            // Filter out already processed records
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

    /**
     * Load ancestors with optimized queries (loads full records, not just IDs).
     *
     * @param \Illuminate\Support\Collection $records Starting records
     * @param string $hierarchyFieldId FK field name
     * @param string $primaryKey PK field name
     * @param array $params Query params for relations/select
     * @return \Illuminate\Support\Collection All records including ancestors
     */
    private function loadAncestorsOptimized(
        \Illuminate\Support\Collection $records,
        string                         $hierarchyFieldId,
        string                         $primaryKey,
        array                          $params
    ): \Illuminate\Support\Collection
    {
        $existingIds = $records->pluck($primaryKey)->toArray();
        $ancestorIds = [];

        // Collect all ancestor IDs first (this is fast, just IDs)
        foreach ($records as $record) {
            $parentId = $record->{$hierarchyFieldId};
            while ($parentId !== null && !in_array($parentId, $existingIds) && !in_array($parentId, $ancestorIds)) {
                $ancestorIds[] = $parentId;
                $parent = $this->modelClass->query()
                    ->select([$primaryKey, $hierarchyFieldId])
                    ->find($parentId);
                $parentId = $parent ? $parent->{$hierarchyFieldId} : null;
            }
        }

        // Load all ancestors in a single query with relations/select
        if (!empty($ancestorIds)) {
            $query = $this->modelClass->query()->whereIn($primaryKey, $ancestorIds);

            if (isset($params['relations'])) {
                $query = $this->relations($query, $params['relations'], null);
            }
            if (isset($params['select'])) {
                $query = $query->select($params['select']);
            }

            $ancestors = $query->get();
            $records = $records->merge($ancestors)->unique($primaryKey);
        }

        return $records;
    }

    /**
     * Find a node in a tree by its ID.
     *
     * @param array $tree Tree structure
     * @param mixed $nodeId ID to find
     * @param string $primaryKey Primary key name
     * @return array|null The found node or null
     */
    private function findNodeInTree(array $tree, mixed $nodeId, string $primaryKey): ?array
    {
        foreach ($tree as $node) {
            if (($node[$primaryKey] ?? null) == $nodeId) {
                return $node;
            }
            if (isset($node['children']) && !empty($node['children'])) {
                $found = $this->findNodeInTree($node['children'], $nodeId, $primaryKey);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
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
        $result = $this->list_all($params);
        $data = $this->extractExportData($result);
        $columns = $this->resolveExportColumns($params);
        $filename = $params['filename'] ?? 'excel.xlsx';
        return Excel::download(new ModelExport($data, $columns), $filename);
    }

    public function exportPdf($params)
    {
        $result = $this->list_all($params);
        $data = $this->extractExportData($result);
        $columns = $this->resolveExportColumns($params);
        $template = $params['template'] ?? 'pdf';
        $filename = $params['filename'] ?? 'pdf_file.pdf';
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($template, [
            'data' => $data,
            'columns' => $columns,
            'model' => $this->modelClass,
            'params' => $params,
        ]);
        // download PDF file with download method
        return $pdf->download($filename);
    }

    private function extractExportData(mixed $result): array
    {
        if ($result instanceof LengthAwarePaginator) {
            return $result->items();
        }
        if (is_array($result) && array_key_exists('data', $result)) {
            return $result['data'];
        }
        if (is_array($result)) {
            return $result;
        }
        return [];
    }

    private function resolveExportColumns(array $params): array
    {
        if (array_key_exists('columns', $params)) {
            return $this->normalizeExportColumns($params['columns']);
        }
        $select = $params['select'] ?? '*';
        if ($select === '*') {
            return $this->modelClass->getFillable();
        }
        if (is_array($select) && count($select) === 1 && $select[0] === '*') {
            return $this->modelClass->getFillable();
        }
        $normalized = $this->normalizeExportColumns($select);
        return empty($normalized) ? $this->modelClass->getFillable() : $normalized;
    }

    private function normalizeExportColumns(mixed $columns): array
    {
        if (is_string($columns)) {
            $columns = array_filter(array_map('trim', explode(',', $columns)));
        }
        if (!is_array($columns)) {
            return [];
        }
        return array_values(array_filter($columns, static fn($value) => $value !== ''));
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

    // ========================================================================
    // HIERARCHY METHODS - Self-referencing hierarchical listing
    // ========================================================================

    /**
     * Default hierarchy configuration values
     */
    private const HIERARCHY_DEFAULTS = [
        'children_key' => 'children',
        'max_depth' => null,
        'filter_mode' => 'match_only',
        'include_empty_children' => true,
    ];

    /**
     * Valid filter modes for hierarchy
     */
    private const HIERARCHY_FILTER_MODES = [
        'match_only',       // Only nodes that match the filter
        'with_ancestors',   // Matching nodes + their ancestors up to root
        'with_descendants', // Matching nodes + all their descendants
        'full_branch',      // Matching nodes + ancestors + descendants
        'root_filter',      // Filter only applies to root nodes, descendants included without filter
    ];

    /**
     * Normalize hierarchy parameter to standard format.
     *
     * @param mixed $hierarchy Raw hierarchy parameter (true, false, or object)
     * @return array|null Normalized hierarchy config or null if disabled
     */
    private function normalizeHierarchyParams(mixed $hierarchy): ?array
    {
        if ($hierarchy === null || $hierarchy === false) {
            return null;
        }

        // Simple boolean true - use all defaults
        if ($hierarchy === true || $hierarchy === 'true' || $hierarchy === '1') {
            return self::HIERARCHY_DEFAULTS;
        }

        // Parse JSON string if needed
        if (is_string($hierarchy)) {
            $decoded = json_decode($hierarchy, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $hierarchy = $decoded;
            } else {
                return null;
            }
        }

        if (!is_array($hierarchy)) {
            return null;
        }

        // Check for enabled flag
        if (isset($hierarchy['enabled']) && !$hierarchy['enabled']) {
            return null;
        }

        // Merge with defaults
        $config = array_merge(self::HIERARCHY_DEFAULTS, $hierarchy);

        // Validate filter_mode
        if (!in_array($config['filter_mode'], self::HIERARCHY_FILTER_MODES, true)) {
            throw new HttpException(400, "Invalid hierarchy filter_mode '{$config['filter_mode']}'. Valid modes: " . implode(', ', self::HIERARCHY_FILTER_MODES));
        }

        // Validate max_depth
        if ($config['max_depth'] !== null && (!is_int($config['max_depth']) || $config['max_depth'] < 1)) {
            throw new HttpException(400, "Hierarchy max_depth must be a positive integer or null.");
        }

        return $config;
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
        // Validate model supports hierarchy
        if (!$this->modelClass->hasHierarchyField()) {
            throw new HttpException(400,
                "Model " . get_class($this->modelClass) . " does not support hierarchical listing. " .
                "Define const HIERARCHY_FIELD_ID to enable this feature."
            );
        }

        // Normalize hierarchy parameters
        $hierarchyConfig = $this->normalizeHierarchyParams($params['hierarchy']);

        if ($hierarchyConfig === null) {
            // Hierarchy disabled, fallback to normal listing
            unset($params['hierarchy']);
            return $this->list_all($params, $toJson);
        }

        $hierarchyFieldId = $this->modelClass->getHierarchyFieldId();
        $primaryKey = $this->modelClass->getKeyName();
        $childrenKey = $hierarchyConfig['children_key'];
        $maxDepth = $hierarchyConfig['max_depth'];
        $filterMode = $hierarchyConfig['filter_mode'];
        $includeEmptyChildren = $hierarchyConfig['include_empty_children'];

        // Build and execute query based on filter mode
        $query = $this->modelClass->query();
        $query = $this->process_query($params, $query);

        // Get all matching records
        $allRecords = $query->get();

        // Apply filter mode logic to get the final set of IDs
        $finalRecords = $this->applyHierarchyFilterMode(
            $allRecords,
            $filterMode,
            $hierarchyFieldId,
            $primaryKey
        );

        // Build the tree structure
        $tree = $this->buildHierarchyTree(
            $finalRecords,
            $hierarchyFieldId,
            $primaryKey,
            $childrenKey,
            $maxDepth,
            $includeEmptyChildren
        );

        // Handle pagination (only for root nodes)
        if (isset($params['pagination'])) {
            return $this->paginateHierarchyRoots($tree, $params['pagination'], $childrenKey);
        }

        return $toJson ? ['data' => $tree] : $tree;
    }

    /**
     * Apply filter mode logic to get the final set of records for hierarchy.
     *
     * @param \Illuminate\Support\Collection $matchedRecords Records that matched the filters
     * @param string $filterMode The filter mode to apply
     * @param string $hierarchyFieldId Foreign key field name
     * @param string $primaryKey Primary key field name
     * @return \Illuminate\Support\Collection Final collection of records
     */
    private function applyHierarchyFilterMode(
        \Illuminate\Support\Collection $matchedRecords,
        string                         $filterMode,
        string                         $hierarchyFieldId,
        string                         $primaryKey
    ): \Illuminate\Support\Collection
    {
        if ($matchedRecords->isEmpty()) {
            return $matchedRecords;
        }

        switch ($filterMode) {
            case 'match_only':
                // Return only matched records, organized hierarchically
                return $matchedRecords;

            case 'with_ancestors':
                // Get ancestors for all matched records
                return $this->addAncestorsToCollection($matchedRecords, $hierarchyFieldId, $primaryKey);

            case 'with_descendants':
                // Get descendants for all matched records
                return $this->addDescendantsToCollection($matchedRecords, $hierarchyFieldId, $primaryKey);

            case 'full_branch':
                // Get both ancestors and descendants
                $withAncestors = $this->addAncestorsToCollection($matchedRecords, $hierarchyFieldId, $primaryKey);
                return $this->addDescendantsToCollection($withAncestors, $hierarchyFieldId, $primaryKey);

            case 'root_filter':
                // Only root nodes were filtered, load all descendants
                $rootRecords = $matchedRecords->filter(fn($r) => $r->{$hierarchyFieldId} === null);
                return $this->addDescendantsToCollection($rootRecords, $hierarchyFieldId, $primaryKey);

            default:
                return $matchedRecords;
        }
    }

    /**
     * Add ancestors to a collection of records.
     *
     * @param \Illuminate\Support\Collection $records Current records
     * @param string $hierarchyFieldId Foreign key field name
     * @param string $primaryKey Primary key field name
     * @return \Illuminate\Support\Collection Records with ancestors added
     */
    private function addAncestorsToCollection(
        \Illuminate\Support\Collection $records,
        string                         $hierarchyFieldId,
        string                         $primaryKey
    ): \Illuminate\Support\Collection
    {
        $existingIds = $records->pluck($primaryKey)->toArray();
        $ancestorIds = [];

        foreach ($records as $record) {
            $parentId = $record->{$hierarchyFieldId};
            while ($parentId !== null && !in_array($parentId, $existingIds) && !in_array($parentId, $ancestorIds)) {
                $ancestorIds[] = $parentId;
                // Fetch parent to get its parent_id
                $parent = $this->modelClass->query()->find($parentId);
                $parentId = $parent ? $parent->{$hierarchyFieldId} : null;
            }
        }

        if (!empty($ancestorIds)) {
            $ancestors = $this->modelClass->query()
                ->whereIn($primaryKey, $ancestorIds)
                ->get();
            $records = $records->merge($ancestors)->unique($primaryKey);
        }

        return $records;
    }

    /**
     * Add descendants to a collection of records.
     *
     * @param \Illuminate\Support\Collection $records Current records
     * @param string $hierarchyFieldId Foreign key field name
     * @param string $primaryKey Primary key field name
     * @return \Illuminate\Support\Collection Records with descendants added
     */
    private function addDescendantsToCollection(
        \Illuminate\Support\Collection $records,
        string                         $hierarchyFieldId,
        string                         $primaryKey
    ): \Illuminate\Support\Collection
    {
        $existingIds = $records->pluck($primaryKey)->toArray();
        $allDescendantIds = [];

        // BFS to get all descendants
        $queue = $existingIds;
        while (!empty($queue)) {
            $childIds = $this->modelClass->query()
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
            $descendants = $this->modelClass->query()
                ->whereIn($primaryKey, $allDescendantIds)
                ->get();
            $records = $records->merge($descendants)->unique($primaryKey);
        }

        return $records;
    }

    /**
     * Build a hierarchical tree structure from a flat collection.
     *
     * @param \Illuminate\Support\Collection $records Flat collection of records
     * @param string $hierarchyFieldId Foreign key field name (parent_id)
     * @param string $primaryKey Primary key field name
     * @param string $childrenKey Key name for children array in output
     * @param int|null $maxDepth Maximum depth to build (null = unlimited)
     * @param bool $includeEmptyChildren Whether to include empty children arrays
     * @return array Hierarchical tree structure
     */
    private function buildHierarchyTree(
        \Illuminate\Support\Collection $records,
        string                         $hierarchyFieldId,
        string                         $primaryKey,
        string                         $childrenKey = 'children',
        ?int                           $maxDepth = null,
        bool                           $includeEmptyChildren = true
    ): array
    {
        if ($records->isEmpty()) {
            return [];
        }

        // Convert to array and index by primary key
        $recordsById = [];
        $recordIds = [];
        foreach ($records as $record) {
            $id = $record->{$primaryKey};
            $recordIds[] = $id;
            $recordsById[$id] = $record->toArray();
            if ($includeEmptyChildren) {
                $recordsById[$id][$childrenKey] = [];
            }
        }

        // Build tree by attaching children to parents
        $roots = [];

        foreach ($recordsById as $id => &$record) {
            $parentId = $record[$hierarchyFieldId] ?? null;

            // Check if parent exists in our dataset
            if ($parentId === null || !isset($recordsById[$parentId])) {
                // This is a root node (or parent not in dataset)
                $roots[] = &$record;
            } else {
                // Attach to parent
                if (!isset($recordsById[$parentId][$childrenKey])) {
                    $recordsById[$parentId][$childrenKey] = [];
                }
                $recordsById[$parentId][$childrenKey][] = &$record;
            }
        }
        unset($record);

        // Apply max depth if specified
        if ($maxDepth !== null) {
            $roots = $this->limitTreeDepth($roots, $childrenKey, $maxDepth);
        }

        // Remove empty children arrays if not wanted
        if (!$includeEmptyChildren) {
            $roots = $this->removeEmptyChildren($roots, $childrenKey);
        }

        return $roots;
    }

    /**
     * Limit tree depth by removing children beyond max depth.
     *
     * @param array $nodes Current level nodes
     * @param string $childrenKey Key name for children array
     * @param int $maxDepth Maximum depth allowed
     * @param int $currentDepth Current depth level
     * @return array Nodes with depth limited
     */
    private function limitTreeDepth(array $nodes, string $childrenKey, int $maxDepth, int $currentDepth = 0): array
    {
        if ($currentDepth >= $maxDepth) {
            // Remove children at this level
            foreach ($nodes as &$node) {
                $node[$childrenKey] = [];
            }
            return $nodes;
        }

        foreach ($nodes as &$node) {
            if (!empty($node[$childrenKey])) {
                $node[$childrenKey] = $this->limitTreeDepth(
                    $node[$childrenKey],
                    $childrenKey,
                    $maxDepth,
                    $currentDepth + 1
                );
            }
        }

        return $nodes;
    }

    /**
     * Remove empty children arrays from tree nodes.
     *
     * @param array $nodes Tree nodes
     * @param string $childrenKey Key name for children array
     * @return array Nodes with empty children removed
     */
    private function removeEmptyChildren(array $nodes, string $childrenKey): array
    {
        foreach ($nodes as &$node) {
            if (isset($node[$childrenKey])) {
                if (empty($node[$childrenKey])) {
                    unset($node[$childrenKey]);
                } else {
                    $node[$childrenKey] = $this->removeEmptyChildren($node[$childrenKey], $childrenKey);
                }
            }
        }

        return $nodes;
    }

    /**
     * Paginate hierarchy by root nodes.
     *
     * @param array $tree Full tree structure
     * @param mixed $pagination Pagination parameters
     * @param string $childrenKey Key name for children array
     * @return array Paginated result
     */
    private function paginateHierarchyRoots(array $tree, mixed $pagination, string $childrenKey): array
    {
        if (is_string($pagination)) {
            $pagination = json_decode($pagination, true);
        }

        $totalRoots = count($tree);

        // Handle infinity/cursor pagination
        if (isset($pagination['infinity']) && $pagination['infinity'] === true) {
            $pageSize = $pagination['pageSize'] ?? $pagination['pagesize'] ?? $this->modelClass->getPerPage();
            $cursor = $pagination['cursor'] ?? null;

            // Simple cursor implementation for hierarchy (index-based)
            $startIndex = 0;
            if ($cursor !== null) {
                $decodedCursor = json_decode(base64_decode($cursor), true);
                $startIndex = $decodedCursor['index'] ?? 0;
            }

            $pagedRoots = array_slice($tree, $startIndex, $pageSize);
            $nextIndex = $startIndex + $pageSize;
            $hasMore = $nextIndex < $totalRoots;

            $nextCursor = $hasMore
                ? base64_encode(json_encode(['index' => $nextIndex]))
                : null;

            return [
                'data' => $pagedRoots,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ];
        }

        // Standard offset pagination
        $page = $pagination['page'] ?? 1;
        $pageSize = $pagination['pageSize'] ?? $pagination['pagesize'] ?? $this->modelClass->getPerPage();

        $offset = ($page - 1) * $pageSize;
        $pagedRoots = array_slice($tree, $offset, $pageSize);

        $lastPage = (int)ceil($totalRoots / $pageSize);

        return [
            'current_page' => $page,
            'data' => $pagedRoots,
            'per_page' => $pageSize,
            'total' => $totalRoots,
            'last_page' => $lastPage,
            'from' => $totalRoots > 0 ? $offset + 1 : null,
            'to' => $totalRoots > 0 ? min($offset + $pageSize, $totalRoots) : null,
        ];
    }
}
