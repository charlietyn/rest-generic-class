<?php
/**Generate by ASGENS
 * @author Charlietyn
 */


namespace Ronu\RestGenericClass\Core\Services;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use Nwidart\Modules\Facades\Module;
use Ronu\RestGenericClass\Core\Traits\HasDynamicFilter;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @property Model $modelClass
 *
 * */
class BaseService
{
    use HasDynamicFilter;

    /** @var BaseModel|string $modelClass */
    public string|BaseModel|Model $modelClass = '';

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
        $staticClass = $this->getStaticClass();
        return $staticClass::RELATIONS;
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
        if (!$relations) {
            return [];
        }

        // Parse JSON string
        if (is_string($relations)) {
            $decoded = json_decode($relations, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $relations = $decoded;
            } elseif ($relations !== 'all') {
                $relations = [$relations];
            }
        }

        if (!is_array($relations)) {
            return [];
        }

        // Handle "all" shortcut
        if (in_array('all', $relations, true)) {
            $allowedRelations = $this->getRelationsForModel($this->modelClass);
            $relations = $allowedRelations;
        }

        $normalized = [];

        foreach ($relations as $relationString) {
            if (!is_string($relationString)) {
                continue;
            }

            $parsed = $this->parseRelationWithFields($relationString);
            $normalized[] = $parsed;
        }

        return $normalized;
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
     * The method accepts an array of elements where each element can be a JSON string
     * or an array that contains column and direction pairs. The query will be ordered
     * according to the specified columns and directions.
     *
     * @param Builder $query The query builder instance to apply the ordering on.
     * @param array|string $params The parameters for ordering, can be a JSON string
     *                             or an array of column-direction pairs.
     * @return Builder The query builder instance with applied ordering.
     */
    private function order_by(Builder $query, array|string $params): Builder
    {
        foreach ($params as $elements) {
            if (is_string($elements)) {
                $elements = json_decode($elements, true);
            }
            foreach ($elements as $index => $parameter) {
                $query = $query->orderBy($index, $parameter);
            }
        }
        return $query;
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
        // Reset counters
        $this->currentDepth = 0;
        $this->conditionCount = 0;

        $nested = isset($params['_nested']) ? $params['_nested'] : false;

        // 1. Apply equality filters (legacy attr/eq)
        if (isset($params["attr"])) {
            $query = $this->eq_attr($query, $params['attr']);
        }

        // 2. Parse oper
        $oper = $params['oper'] ?? null;
        if (is_string($oper)) {
            $decoded = json_decode($oper, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $oper = $decoded;
            }
        }

        // 3. Apply oper tree (includes whereHas for relations)
        // This filters the ROOT dataset based on related records
        if (!empty($oper)) {
            $query = $this->applyOperTree($query, $oper, 'and', $this->modelClass);
        }

        // 4. Eager load relations with field selection
        // This loads the related records (optionally filtered if _nested=true)
        // IMPORTANT: This respects the "relation:field1,field2" syntax
        if (isset($params['relations'])) {
            $query = $this->relations($query, $params['relations'], $nested ? $oper : null);
        }

        // 5. Select clause for main model
        if (isset($params['select'])) {
            $query = $query->select($params['select']);
        } else {
            $query = $query->select($this->modelClass->getTable() . '.*');
        }

        // 6. Order by
        if (isset($params['orderby'])) {
            $query = $this->order_by($query, $params['orderby']);
        }

        return $query;
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
        // Check if hierarchy mode is requested
        if (isset($params['hierarchy']) && $params['hierarchy']) {
            return $this->listHierarchy($params, $toJson);
        }

        $query = $this->modelClass->query();
        $query = $this->process_query($params, $query);
        if (isset($params['pagination'])) {
            $pagination_lower = array_change_key_case($params['pagination']);
            $pagesize = array_key_exists('pagesize', $pagination_lower) ? $pagination_lower['pagesize'] : $this->modelClass->getPerPage();
            if (!isset($params['pagination']['infinity']) || $params['pagination']['infinity'] !== true)
                return $this->pagination($query, $params['pagination']);
            else {
                $cursor = isset($params['pagination']['cursor']) ? $params['pagination']['cursor'] : null;
                $items = $query->cursorPaginate($pagesize, ['*'], 'cursor', $cursor);
                return [
                    'data' => $items->items(),
                    'next_cursor' => $items->nextCursor()?->encode(),
                    'has_more' => $items->hasMorePages(),
                ];
            }
        }
        $value = $query->get();
        return $toJson ? ['data' => $value->jsonSerialize()] : $value->toArray();
    }

    public function get_one($params, $toJson = true): mixed
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

    public function destroy($id): array
    {
        $this->modelClass = $this->modelClass->query()->findOrFail($id);
        $result = [];
        $result['success'] = true;
        $result['model'] = $this->modelClass;
        if (!$this->modelClass->destroy($id))
            $result['success'] = false;
        return $result;
    }

    public function destroybyid($id): array
    {
        $response = $this->modelClass::destroy($id);
        $result['success'] = $response > 0;
        return $result;
    }

    public function exportExcel($params)
    {
        $result = $this->list_all($params);
        $columns = $params['select'] == "*" ? $this->modelClass->getFillable() : $params['select'];
        return Excel::download(new ModelExport($result['data'], $columns), 'excel.xlsx');
    }

    public function exportPdf($params)
    {
        $result = $this->list_all($params);
        $columns = $params['select'] == "*" ? $this->modelClass->getFillable() : $params['select'];
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf', []);
        // download PDF file with download method
        return $pdf->download('pdf_file.pdf');
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
        if (empty($oper)) {
            return [];
        }
        if (is_string($oper)) {
            $decoded = json_decode($oper, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $oper = $decoded;
            }
        }
        if (!is_array($oper)) {
            return [];
        }
        if (array_is_list($oper)) {
            return ['and' => $oper];
        }
        $normalized = [];
        foreach ($oper as $key => $value) {
            if (in_array($key, ['and', 'or'], true)) {
                if (!is_array($value)) {
                    throw new HttpException(400, "Logical operator '{$key}' must have array value.");
                }
                $normalized[$key] = $value;
            } else {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
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
        if (is_object($modelClass)) {
            $modelClass = get_class($modelClass);
        }
        $model = is_string($modelClass) ? new $modelClass : $modelClass;
        if (defined("{$modelClass}::RELATIONS")) {
            return $modelClass::RELATIONS;
        }

        $strict = config('rest-generic-class.filtering.strict_relations', true);

        if ($strict) {
            throw new HttpException(
                500,
                "Model {$modelClass} must define const RELATIONS for security. " .
                "Set 'filtering.strict_relations' => false to auto-detect (not recommended)."
            );
        }
        return $this->autoDetectRelations($model);
    }

    /**
     * Auto-detect relations via reflection (fallback, not recommended for production).
     */
    private function autoDetectRelations($model): array
    {
        $relations = [];
        $methods = get_class_methods($model);

        foreach ($methods as $method) {
            if (method_exists($model, $method) && !in_array($method, ['exists', 'increment', 'decrement'])) {
                try {
                    $reflection = new \ReflectionMethod($model, $method);

                    // Skip protected/private, static, magic methods
                    if (!$reflection->isPublic() || $reflection->isStatic() || str_starts_with($method, '_')) {
                        continue;
                    }

                    // Check return type
                    $returnType = $reflection->getReturnType();
                    if ($returnType && !$returnType->isBuiltin()) {
                        $typeName = $returnType->getName();
                        if (is_subclass_of($typeName, \Illuminate\Database\Eloquent\Relations\Relation::class)) {
                            $relations[] = $method;
                        }
                    }
                } catch (\ReflectionException $e) {
                    continue;
                }
            }
        }

        return $relations;
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
        $allowedRelations = $this->getRelationsForModel($modelClass);
        $relationFilters = [];

        foreach ($normalized as $key => $value) {
            // Skip logical operators
            if (in_array($key, ['and', 'or'], true)) {
                continue;
            }
            // Check if key is allowed relation
            $isAllowed = false;
            // Support dot notation: user.roles
            if (str_contains($key, '.')) {
                $firstSegment = explode('.', $key)[0];
                $isAllowed = in_array($firstSegment, $allowedRelations, true);
            } else {
                $isAllowed = in_array($key, $allowedRelations, true);
            }

            if (!$isAllowed) {
                throw new HttpException(
                    400,
                    "Relation '{$key}' is not allowed for filtering on model " .
                    (is_object($modelClass) ? get_class($modelClass) : $modelClass) .
                    ". Allowed relations: " . implode(', ', $allowedRelations)
                );
            }

            $relationFilters[$key] = $value;
        }

        return $relationFilters;
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
        $cleaned = [];

        foreach ($normalized as $key => $value) {
            // Keep only 'and'/'or' keys
            if (in_array($key, ['and', 'or'], true)) {
                $cleaned[$key] = $value;
            }
            // Everything else is a relation (will be processed separately)
        }

        return $cleaned;
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
        // Check recursion limits
        $this->currentDepth++;
        $maxDepth = config('rest-generic-class.filtering.max_depth', 5);

        if ($this->currentDepth > $maxDepth) {
            throw new HttpException(400, "Maximum nesting depth ({$maxDepth}) exceeded.");
        }

        try {
            // 1. Normalize structure
            $normalized = $this->normalizeOperNode($oper);

            if (empty($normalized)) {
                return $query;
            }

            // Use current model if not specified
            $modelClass = $modelClass ?? $this->modelClass;

            // 2. Extract base conditions and relation filters
            $baseOper = $this->stripRelationFiltersForModel($normalized, $modelClass);
            $relationFilters = $this->extractRelationFiltersForModel($normalized, $modelClass);

            // 3. Apply base conditions (and/or blocks)
            if (!empty($baseOper)) {
                // Count conditions
                foreach ($baseOper as $conditions) {
                    if (is_array($conditions)) {
                        $this->conditionCount += count($conditions);
                    }
                }

                $maxConditions = config('rest-generic-class.filtering.max_conditions', 100);
                if ($this->conditionCount > $maxConditions) {
                    throw new HttpException(400, "Maximum conditions ({$maxConditions}) exceeded.");
                }

                // ✅ PASS MODEL TO applyFilters for table prefixing
                $query = $this->applyFilters($query, $baseOper, $boolean, $modelClass);
            }

            // 4. Apply nested whereHas for each relation
            foreach ($relationFilters as $relationPath => $subOper) {
                $query = $this->applyNestedWhereHas($query, $relationPath, $subOper, $boolean, $modelClass);
            }

            return $query;

        } finally {
            $this->currentDepth--;
        }
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
        string $relationPath,
        mixed $subOper,
        string $boolean,
                $currentModel
    ): Builder {
        $method = $boolean === 'or' ? 'orWhereHas' : 'whereHas';

        // Handle dot notation: user.roles
        if (str_contains($relationPath, '.')) {
            $segments = explode('.', $relationPath);
            $firstRelation = array_shift($segments);
            $remainingPath = implode('.', $segments);

            // Get related model for first segment
            $relatedModel = $this->getRelatedModel($currentModel, $firstRelation);

            return $query->{$method}($firstRelation, function ($relationQuery) use ($remainingPath, $subOper, $boolean, $relatedModel) {
                // Recurse into nested relation
                if ($remainingPath) {
                    $this->applyNestedWhereHas($relationQuery, $remainingPath, $subOper, $boolean, $relatedModel);
                } else {
                    // Terminal node: apply subOper with correct model context
                    $this->applyOperTree($relationQuery, $subOper, $boolean, $relatedModel);
                }
            });
        }

        // Simple relation (no dots)
        $relatedModel = $this->getRelatedModel($currentModel, $relationPath);

        return $query->{$method}($relationPath, function ($relationQuery) use ($subOper, $boolean, $relatedModel) {
            // ✅ PASS RELATED MODEL to applyOperTree for correct table prefixing
            $this->applyOperTree($relationQuery, $subOper, $boolean, $relatedModel);
        });
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
        if (is_object($modelClass)) {
            $model = $modelClass;
            $modelClass = get_class($modelClass);
        } else {
            $model = new $modelClass;
        }

        if (!method_exists($model, $relationName)) {
            throw new HttpException(400, "Relation '{$relationName}' does not exist on model {$modelClass}.");
        }

        try {
            $relation = $model->{$relationName}();

            if (!$relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                throw new HttpException(400, "Method '{$relationName}' on {$modelClass} is not a valid Eloquent relation.");
            }

            return get_class($relation->getRelated());

        } catch (\Throwable $e) {
            throw new HttpException(400, "Failed to resolve related model for '{$relationName}': " . $e->getMessage());
        }
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
        if (empty($fields)) {
            return $fields;
        }

        $model = is_string($parentModel) ? new $parentModel : $parentModel;

        try {
            $relation = $model->{$relationName}();

            // Always include primary key of related model
            $relatedKeyName = $relation->getRelated()->getKeyName();
            if (!in_array($relatedKeyName, $fields, true)) {
                array_unshift($fields, $relatedKeyName);
            }

            // Include foreign key based on relation type
            if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                // BelongsTo: need foreign key on parent
                // No need to add to $fields (fields are for related model)
            } elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasOneOrMany) {
                // HasMany/HasOne: need foreign key on related model
                $foreignKey = $relation->getForeignKeyName();
                $foreignKeyName = last(explode('.', $foreignKey)); // Remove table prefix

                if (!in_array($foreignKeyName, $fields, true)) {
                    array_unshift($fields, $foreignKeyName);
                }
            } elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
                // BelongsToMany: pivot keys are handled automatically by Laravel
                // Just ensure we have the primary key (already done above)
            }

            return array_values(array_unique($fields));

        } catch (\Throwable $e) {
            // If we can't determine foreign keys, return fields as-is
            Log::channel('rest-generic-class')->warning("Could not determine foreign keys for relation {$relationName}: " . $e->getMessage());
            return $fields;
        }
    }

    /**
     * Handle nested relation with field selection (e.g., "user.roles:id,name")
     *
     * @param array $normalized Normalized relations array
     * @return array Processed for Laravel's with() method
     */
    private function processNestedRelationsWithFields(array $normalized): array
    {
        $processed = [];

        foreach ($normalized as $parsed) {
            $relation = $parsed['relation'];
            $fields = $parsed['fields'];

            if (!str_contains($relation, '.')) {
                // Simple relation, already handled
                $processed[] = $parsed;
                continue;
            }

            // Nested relation: user.roles
            $segments = $parsed['segments'];

            if ($fields) {
                // Need to ensure foreign keys at each level
                // This is complex - Laravel handles it when you use the string syntax
                // "user.roles:id,name" works out of the box
                $key = $relation . ':' . implode(',', $fields);
                $processed[] = [
                    'relation' => $relation,
                    'key' => $key,
                    'fields' => $fields,
                    'segments' => $segments,
                    'base' => $segments[0]
                ];
            } else {
                $processed[] = $parsed;
            }
        }

        return $processed;
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
        string $filterMode,
        string $hierarchyFieldId,
        string $primaryKey
    ): \Illuminate\Support\Collection {
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
        string $hierarchyFieldId,
        string $primaryKey
    ): \Illuminate\Support\Collection {
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
        string $hierarchyFieldId,
        string $primaryKey
    ): \Illuminate\Support\Collection {
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
        string $hierarchyFieldId,
        string $primaryKey,
        string $childrenKey = 'children',
        ?int $maxDepth = null,
        bool $includeEmptyChildren = true
    ): array {
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

        $lastPage = (int) ceil($totalRoots / $pageSize);

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
