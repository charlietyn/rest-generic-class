<?php
/** Generate by ASGENS
 * @author Charlietyn
 */

namespace Ronu\RestGenericClass\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Class BaseService
 *
 * This class provides a generic service layer for handling CRUD operations,
 * pagination, filtering, and exporting data for Eloquent models.
 *
 * @package Ronu\RestGenericClass\Core\Services
 */
class BaseService
{
    /**
     * The Eloquent model class associated with this service.
     *
     * @var BaseModel|string $modelClass
     */
    public $modelClass = '';

    /**
     * BaseService constructor.
     *
     * Initializes the service with the given model class.
     *
     * @param Model|string $modelClass The model class to be used by the service.
     */
    public function __construct($modelClass)
    {
        $this->modelClass = new $modelClass;
    }

    /**
     * Handles pagination for a query.
     *
     * @param Builder $query The query to paginate.
     * @param mixed $pagination Pagination configuration (can be a string or array).
     * @return LengthAwarePaginator The paginated results.
     */
    private function pagination(Builder $query, mixed $pagination): LengthAwarePaginator
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

    /**
     * Adds relations to a query.
     *
     * @param Builder $query The query to add relations to.
     * @param mixed $params The relations to load (can be 'all' or an array of relations).
     * @param array $oper Additional operations for nested relations.
     * @return Builder The query with relations.
     */
    private function relations(Builder $query, mixed $params, array $oper = []): Builder
    {
        $flatt_array = $oper ? $this->flatten_array($oper) : [];
        /** @var Builder $query */
        if ($params == 'all' || array_search("all", $params) !== false)
            $query = $query->with($this->modelClass::RELATIONS);
        else {
            $query = $query->with($params);
//            foreach ($params as $p)
//                $query = $query->with($p, function ($query) use ($flatt_array,$p) {
//                    if(array_key_exists($p,$flatt_array)) {
//                        $array_values = array_values($this->process_oper($flatt_array[$p]));
//                        $query->where(...$array_values);
//                    }
//                });
        }
        return $query;
    }

    /**
     * Flattens a multi-dimensional array.
     *
     * @param array $array The array to flatten.
     * @return array The flattened array.
     */
    private function flatten_array(array $array): array
    {
        return iterator_to_array(
            new \RecursiveIteratorIterator(new \RecursiveArrayIterator($array))
        );
    }

    /**
     * Adds equality conditions to a query.
     *
     * @param Builder $query The query to add conditions to.
     * @param mixed $params The conditions to apply (can be a string or array).
     * @return Builder The query with conditions.
     */
    private function eq_attr(Builder $query, mixed $params): Builder
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
     * Adds ordering to a query.
     *
     * @param Builder $query The query to add ordering to.
     * @param mixed $params The ordering configuration (can be a string or array).
     * @return Builder The query with ordering.
     */
    private function order_by(Builder $query, mixed $params): Builder
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
     * Adds complex conditions to a query.
     *
     * @param Builder $query The query to add conditions to.
     * @param mixed $params The conditions to apply (can be a string or array).
     * @param string $condition The logical condition ('and' or 'or').
     * @return Builder The query with conditions.
     */
    private function oper(Builder $query, mixed $params, string $condition = "and"): Builder
    {

        if (is_string($params))
            $params = json_decode($params, true);
        foreach ($params as $index => $parameter) {
            if ($index === "or" || $index === "and")
                $condition = $index;
            $where = $condition == "and" ? "where" : "orWhere";
            if ((!is_numeric($index) && ($index != "or" && $index != "and")) && (is_array($parameter) && (array_key_exists("or", $parameter) || array_key_exists("and", $parameter))) || ($index === "or" || $index === "and")) {
                if (array_key_exists("or", $parameter))
                    $query = $this->oper($query, $parameter['or'], "or");
                elseif (array_key_exists("and", $parameter))
                    $query = $this->oper($query, $parameter['and'], "and");
                elseif ($index === "or" || "and")
                    $query = $this->oper($query, $parameter, $index);
            } else {
                if (is_array($parameter) || str_contains($parameter, '|')) {
                    if (is_array($parameter)) {
                        $index = array_key_first($parameter);
                        $parameter = array_pop($parameter);
                    }
                    $oper = $this->process_oper($parameter);
                    if (array_search(strtolower("notbetween"), array_map('strtolower', $oper))) {
                        $where = $where . "NotBetween";
                    } elseif (array_search(strtolower("between"), array_map('strtolower', $oper))) {
                        $where = $where . "Between";
                    } elseif (array_search(strtolower("notin"), array_map('strtolower', $oper))) {
                        $where = $where . "NotIn";
                    } elseif (array_search(strtolower("in"), array_map('strtolower', $oper))) {
                        $where = $where . "In";
                    } elseif (array_search(strtolower("notnull"), array_map('strtolower', $oper))) {
                        $where = $where . "NotNull";
                    } elseif (array_search(strtolower("null"), array_map('strtolower', $oper))) {
                        $where = $where . "Null";
                    }
                    if (strpos($where, "etween") || strpos(strtolower($where), "in")) {
                        $oper[2] = [...$oper];
                        if (strpos(strtolower($where), "in")) {
                            unset($oper[2][0]);
                            unset($oper[2][1]);
                        }
                        if (strpos(strtolower($where), "etween")) {
                            unset($oper[2][0]);
                            unset($oper[2][1]);
                        }
                        unset($oper[3]);
                        unset($oper[1]);
                    }
                    if (strpos(strtolower($where), "null")) {
                        $oper = [$oper[0]];
                    }

                    $nestedWhere = $where . "Has";
                    if (is_numeric($index))
                        $query = $query->$where(...$oper);
                    else {
                        $query = $query->$nestedWhere($index, function ($query) use ($oper, $where) {
                            $query->where(...$oper);
                        });
                    }
                }
            }
        }
        return $query;
    }

    /**
     * Processes an operation string into an array.
     *
     * @param string $value The operation string (e.g., "field|operator|value").
     * @return array|false The processed operation array or false on failure.
     */
    public function process_oper(string $value): array|false
    {
        return explode("|", $value);
    }

    /**
     * Processes a query with the given parameters.
     *
     * @param array $params The parameters to process (e.g., relations, filters, pagination).
     * @param Builder $query The query to process.
     * @return Builder The processed query.
     */
    public function process_query(array $params, Builder $query): Builder
    {
        $nested = isset($params['_nested']) ? $params['_nested'] : false;
        if (isset($params["attr"])) {
            $query->av = $this->eq_attr($query, $params['attr']);
        }
        if (isset($params['relations'])) {
            $query = $this->relations($query, $params['relations'], $nested ? $params["oper"] : null);
        }
        if (isset($params['select'])) {
            $query = $query->select($params['select']);
        } else {
            $query = $query->select($this->modelClass->getTable() . '.*');
        }
        if (isset($params['orderby'])) {
            $query = $this->order_by($query, $params['orderby']);
        }
        if (isset($params['oper'])) {
            $query = $this->oper($query, $params['oper']);
        }
        return $query;
    }

    /**
     * Retrieves a list of all records with optional pagination.
     *
     * @param array $params The parameters for filtering, sorting, and pagination.
     * @param bool $toJson Whether to return the result as JSON.
     * @return mixed The list of records.
     */
    public function list_all(array $params, bool $toJson = true): mixed
    {
        $query = $this->modelClass->query();
        $query = $this->process_query($params, $query);
        if (isset($params['pagination']))
            return $this->pagination($query, $params['pagination']);
        $value = $query->get();
        return $toJson ? ['data' => $value->jsonSerialize()] : $value->toArray();
    }

    /**
     * Retrieves a single record by ID.
     *
     * @param array $params The parameters for filtering and relations.
     * @param bool $toJson Whether to return the result as JSON.
     * @return mixed The single record.
     */
    public function get_one(array $params, bool $toJson = true): mixed
    {
        $query = $this->modelClass->query();
        $query = $this->process_query($params, $query);
        unset($params['pagination']);
        $value = $query->get();
        return $toJson ? ['data' => $value->jsonSerialize()[0]] : $value->toArray()[0];
    }

    /**
     * Retrieves the parent records for a hierarchical model.
     *
     * @param Model|mixed $modelClass The model class.
     * @param array|null $attributes The attributes to validate.
     * @param string $scenario The validation scenario.
     * @param bool $specific Whether to validate specific attributes.
     * @return array The parent records.
     */
    public function get_parents(mixed $modelClass, array $attributes = null, string $scenario = 'create', bool $specific = false): array
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

    /**
     * Saves the parent records for a hierarchical model.
     *
     * @param Model|mixed $modelClass The model class.
     * @param array|null $attributes The attributes to save.
     * @param string $scenario The save scenario.
     * @param bool $specific Whether to save specific attributes.
     * @return array The saved parent records.
     */
    public function save_parents(mixed $modelClass, array $attributes = null, string $scenario = 'create', bool $specific = false): array
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

    /**
     * Validates the parent records for a hierarchical model.
     *
     * @param array $attributes The attributes to validate.
     * @param string|null $scenario The validation scenario.
     * @param bool $specific Whether to validate specific attributes.
     * @return array The validation results.
     */
    private function parents_validate(array $attributes, string $scenario = null, bool $specific = false): array
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

    /**
     * Validates all attributes for the model and its parents.
     *
     * @param array $attributes The attributes to validate.
     * @param string $scenario The validation scenario.
     * @param bool $specific Whether to validate specific attributes.
     * @return array The validation results.
     */
    public function validate_all(array $attributes, string $scenario = 'create', bool $specific = false): array
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

    /**
     * Saves a model with the given attributes.
     *
     * @param array $attributes The attributes to save.
     * @param string $scenario The save scenario.
     * @param bool $validate Whether to validate before saving.
     * @return array The save results.
     */
    public function save(array $attributes, string $scenario = 'create', bool $validate = false): array
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
     * Creates a new record or multiple records.
     *
     * @param array $params The parameters for creating the record(s).
     * @return array The creation results.
     * @throws HttpException If the parameters are invalid.
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

    /**
     * Saves multiple records.
     *
     * @param array $attributes The attributes for the records.
     * @param string $scenario The save scenario.
     * @param bool $validate Whether to validate before saving.
     * @return array The save results.
     */
    public function save_array(array $attributes, string $scenario = 'create', bool $validate = false): array
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

    /**
     * Updates a record by ID.
     *
     * @param array $attributes The attributes to update.
     * @param int $id The ID of the record to update.
     * @param bool $validate Whether to validate before updating.
     * @return array The update results.
     */
    public function update(array $attributes, int $id, bool $validate = false): array
    {
        $this->modelClass = $this->modelClass->query()->findOrFail($id);
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

    /**
     * Updates multiple records.
     *
     * @param array $params The parameters for updating the records.
     * @param bool $validate Whether to validate before updating.
     * @return array The update results.
     */
    public function update_multiple(array $params, bool $validate = false): array
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

    /**
     * Retrieves a single record by ID with optional relations.
     *
     * @param array $params The parameters for filtering and relations.
     * @param mixed $id The ID of the record to retrieve.
     * @return mixed The retrieved record.
     */
    public function show(array $params, mixed $id): mixed
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

    /**
     * Deletes a record by ID.
     *
     * @param mixed $id The ID of the record to delete.
     * @return array The deletion results.
     */
    public function destroy(mixed $id): array
    {
        $this->modelClass = $this->modelClass->query()->findOrFail($id);
        $result = [];
        $result['success'] = true;
        $result['model'] = $this->modelClass;
        if (!$this->modelClass->destroy($id))
            $result['success'] = false;
        return $result;
    }

    /**
     * Deletes records by their IDs.
     *
     * @param array $id The IDs of the records to delete.
     * @return array The deletion results.
     */
    public function destroy_by_id(array $id): array
    {
        $response = $this->modelClass::destroy($id);
        $result['success'] = $response > 0;
        return $result;
    }

    /**
     * Exports data to an Excel file.
     *
     * @param array $params The parameters for filtering and selecting data.
     * @return mixed The Excel file download response.
     */
    public function exportExcel(array $params): mixed
    {
        $result = $this->list_all($params);
        $columns = $params['select'] == "*" ? $this->modelClass->getFillable() : $params['select'];
        return Excel::download(new ModelExport($result['data'], $columns), 'excel.xlsx');
    }

    /**
     * Exports data to a PDF file.
     *
     * @param array $params The parameters for filtering and selecting data.
     * @return mixed The PDF file download response.
     */
    public function exportPdf(array $params): mixed
    {
        $result = $this->list_all($params);
        $columns = $params['select'] == "*" ? $this->modelClass->getFillable() : $params['select'];
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf', []);
        return $pdf->download('pdf_file.pdf');
    }

    /**
     * Sends an email.
     *
     * @param string $view The email view.
     * @param array $variables The variables to pass to the view.
     * @param string|mixed $from The sender's email address.
     * @param string $name The sender's name.
     * @param string $email The recipient's email address.
     * @param string $subject The email subject.
     * @return array The email sending results.
     */
    public static function sendEmail(string $view, array $variables, mixed $from, string $name, string $email, string $subject): array
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
}