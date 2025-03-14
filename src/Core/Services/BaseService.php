<?php
/**Generate by ASGENS
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
 * @property Model $modelClass
 *
 * */
class BaseService
{

    /** @var BaseModel|string $modelClass */
    public $modelClass = '';

    /**
     * Services constructor.
     * @param Model|String $modelClass
     */
    public function __construct($modelClass)
    {
        $this->modelClass = new $modelClass;
    }


    private function pagination($query, $pagination): LengthAwarePaginator
    {
        if (is_string($pagination))
            $pagination = json_decode($pagination, true);
        $currentPage = isset($pagination["page"]) ? $pagination["page"] : 1;
        $pageSize=isset($pagination["pageSize"]) ? $pagination["pageSize"] :  (isset($pagination["pagesize"])?$pagination["pagesize"]:null);
        Paginator::currentPageResolver(function () use ($currentPage) {
            return $currentPage;
        });
        return $query->paginate($pageSize);
    }

    private function relations($query, $params, $oper=[]): Builder
    {
        $flatt_array = $oper?$this->flatten_array($oper):[];
        /**@var Builder $query * */
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

    private function flatten_array(array $array)
    {
        return iterator_to_array(
            new \RecursiveIteratorIterator(new \RecursiveArrayIterator($array)));
    }

    private function eq_attr($query, $params): Builder
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

    private function order_by($query, $params): Builder
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

    private function oper($query, $params, $condition = "and"): Builder
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

    public function process_oper($value): array|false
    {
        return explode("|", $value);
    }

    public function process_query($params, $query): Builder
    {
        $nested=isset($params['_nested'])?$params['_nested']:false;
        if (isset($params["attr"])) {
            $query->av = $this->eq_attr($query, $params['attr']);
        }
        if (isset($params['relations'])) {
            $query = $this->relations($query, $params['relations'], $nested?$params["oper"]:null);
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

    public function list_all($params, $toJson = true): mixed
    {
        $query = $this->modelClass->query();
        $query = $this->process_query($params, $query);
        if (isset($params['pagination']))
            return $this->pagination($query, $params['pagination']);
        $value = $query->get();
        return $toJson ? ['data' => $value->jsonSerialize()] : $value->toArray();
    }

    public function get_one($params, $toJson = true): mixed
    {
        $query = $this->modelClass->query();
        $query = $this->process_query($params, $query);
        unset($params['pagination']);
        $value = $query->get();
        return $toJson ? ['data' => $value->jsonSerialize()[0]] : $value->toArray()[0];
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

    public function save(array $attributes, $scenario = 'create',$validate=false): array
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
        $valid = $validate?$this->validate_all($attributes, $this->modelClass->getScenario()):['success'=>true];
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
     * @throws \HttpException
     */
    public function create(array $params): array
    {
        if (isset($params[strtolower($this->modelClass::MODEL)]) || array_key_exists(0, $params)) {
            $params = $params[strtolower($this->modelClass::MODEL)] ?? $params;
            if (!$params)
                throw new \HttpException(400, 'Bad Request:Params must be an array or object value');
            $result = $this->save_array($params);
        } else {
            $result = $this->save($params);
        }
        return $result;
    }

    public function save_array(array $attributes, $scenario = 'create',$validate=false): array
    {
        $result = [];
        $result['success'] = true;
        foreach ($attributes as $index => $model) {
            $save = $this->save($model, $scenario);
            if (!$save['success']) {
                $result['success'] = false;
                $result['error'][] = [$save['errors'], $save['model']];
            }else{
                $result[]=$save;
            }
        }
        return $result;
    }

    public function update(array $attributes, $id,$validate=false): array
    {
        $this->modelClass = $this->modelClass->query()->findOrFail($id);
        $this->modelClass->setScenario("update");
        $specific = isset($attributes["_specific"]) ? $attributes["_specific"] : false;
        $this->modelClass->fill($attributes);
        $valid = $validate?$this->modelClass->self_validate($this->modelClass->getScenario(), $specific):["success"=>true];
        if ($valid['success']) {
            $this->modelClass->save();
            $result = ["success" => true, "model" => $this->modelClass->jsonSerialize()];
        } else {
            $result = $valid;
        }
        return $result;
    }

    public function update_multiple(array $params,$validate=false): array
    {
        $result = [];
        $result['success'] = true;
        foreach ($params as $index => $item) {
            $id = $item[$this->modelClass->getPrimaryKey()];
            $res = $this->update($item, $id,$validate);
            $result["models"][] = $res;
            if (!$res['success'])
                $result['success'] = false;
        }
        return $result;
    }

    public function show($params, $id): mixed
    {
        $nested=isset($params['_nested'])?$params['_nested']:false;
        $query = $this->modelClass->query();
        if (isset($params['relations'])) {
            $query = $this->relations($query, $params['relations'],$nested?$params["oper"]:null);
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
}
