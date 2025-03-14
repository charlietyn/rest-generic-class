<?php
/**Generate by ASGENS
 *@author Charlietyn
 */

namespace Ronu\RestGenericClass\Core\Models;

use App\Scopes\NonDeletedScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;
use Ronu\RestGenericClass\Core\Extension\Eloquent\Relations\MongoBelongTo;
use Ronu\RestGenericClass\Core\Extension\Eloquent\Relations\MongoHasMany;

class BaseModel extends Model
{
    use ValidatesRequests, HasFactory, Notifiable;

    /**
     * @param array $parameters
     * @return mixed
     */
    protected $scenario = "create";

    /**
     * /**
     * The name of the model name parameters
     *
     * @var string
     */
    const MODEL = '';

    const columns=[];
    /**
     * /**
     * Relations of entity
     *
     * @var array
     */
    const RELATIONS = [];


    const PARENT = [];

    /**
     * @return string
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * @return mixed
     */
    public function getScenario()
    {
        return $this->scenario;
    }

    /**
     * @param mixed $scenario
     */
    public function setScenario($scenario): void
    {
        $this->scenario = $scenario;
    }

    public function hasHierarchy()
    {
        return count(get_called_class()::PARENT) > 0;
    }

    protected function rules($scenario)
    {
        return [];
    }

    /**
     * @param array $parameters
     * @return mixed
     */

    public function self_validate($scenario = 'create', $specific = false, $validate_pk = true)
    {
        $attrKeys=array_keys($this->attributes);
        $originalsRules=$this->rules($scenario);
        $rules=array_filter($originalsRules, function($v,$k) use ($attrKeys) {
            return in_array($k, $attrKeys) || str_contains($v,'required');
        }, ARRAY_FILTER_USE_BOTH);
        if (!$validate_pk) {
            unset($rules[$this->getPrimaryKey()]);
        }
        if ($specific) {
            $rules = array_intersect_key($rules, $this->attributes);
        }
        $valid = $this->getValidationFactory()->make($this->attributes, $rules);
        return ["success" => !$valid->fails(), "errors" => $valid->errors()->getMessages(), 'model' => get_called_class()];
    }

    public function save_model(array $attributes = [], $scenario = 'create')
    {
        $parent = null;
        $this->setScenario($scenario);
        if (count($attributes) === 0)
            $attributes = $this->attributes;
        if (isset($attributes[$this->getPrimaryKey()]) && $scenario=='create') {
            $this->setScenario('update');
        }
        $validate_all = $this->validate_all($attributes, $this->getScenario());
        if (!$validate_all['success'])
            return $validate_all;
        if (count(self::PARENT) > 0) {
            $parent = $this->save_parents($this->modelClass, $attributes, $this->getScenario());
        }
        if ($parent)
            $attributes[$this->getPrimaryKey()] = $parent[$this->getPrimaryKey()];
        $this->fill($attributes);
        $this->save();
        $result = ["success" => true, "model" => $this->getAttributes()];
        return $result;
    }

    public function save_parents($attributes = null, $scenario = 'create', $specific = false)
    {
        $parent = null;
        if ($this->hasHierarchy()) {
            $parent_class = self::PARENT['class'];
            if (!isset($attributes[$this->getPrimaryKey()])) {
                $parent = new $parent_class();
            } else {
                $parent = $parent_class::find($attributes[$this->getPrimaryKey()]);
            }
            $parent->fill($attributes);
            $parent->save();
            if ($parent->hasHierarchy()) {
                $parent->save_parents($parent, $attributes, $scenario, $specific);
            }
        }
        return $parent;
    }

    public function validate_all(array $attributes, $scenario = 'create', $specific = false)
    {
        $validate = [];
        if (isset($attributes[$this->getPrimaryKey()]) && $scenario == 'create')
            $scenario = "update";
        $this->setScenario($scenario);
        if (count(self::PARENT) > 0) {
            $parent_class = self::PARENT['class'];
            if (!isset($attributes[$this->getPrimaryKey()])) {
                $parent = new $parent_class();
            } else {
                $parent = $parent_class::find($attributes[$this->getPrimaryKey()]);
            }
            if (!$parent) {
                $result = ["success" => false, 'error' => "Element not found", "model" => $parent_class];
                return $result;
            }
            $validateparents = $this->parents_validate($attributes, $this->getScenario(), $specific);
            if ($validateparents)
                $validate[] = $validateparents;
        }
        $this->fill($attributes);
        $valid = $this->self_validate($this->getScenario(), $specific, false);
        if ($valid['success'] && count($validate) == 0) {
            $result = ["success" => true, 'error' => []];
        } else {
            if (!$valid['success'])
                array_push($validate, $valid);
            $result = ["success" => false, "errors" => $validate];
        }
        return $result;
    }

    private function parents_validate($attributes, $scenario = null, $specific = false)
    {
        $result = null;
        $parents = $this->get_parents($attributes, $scenario, $specific);
        foreach ($parents as $p) {
            if (count($p['errors']) > 0) {
                $result['success'] = false;
                $result['errors'] = $p['errors'];
                $result['model'] = $p['model'];
            }
        }
        return $result;
    }

    public function get_parents($attributes = null, $scenario = 'create', $specific = false)
    {
        $parent_array = [];
        if ($this->hasHierarchy()) {
            $parent_class = self::PARENT['class'];
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

    static public function create_model(array $params)
    {
        if (isset($params[self::MODEL]) || array_key_exists(0, $params)) {
            $result = self::save_array($params[self::MODEL]);
        } else {
            $class_model = new self();
            $class_model->fill($params);
            $result = $class_model->save($params);
        }
        return $result;
    }

    static public function save_array(array $attributes, $scenario = 'create')
    {
        $result = [];
        $result['success'] = true;
        foreach ($attributes as $index => $model) {
            $class_model = new self();
            $class_model->fill($model);
            $save = $class_model->save();
            if (!$save['success']) {
                $result['success'] = false;
            }
            $result['models'][] = $save;
        }
        return $result;
    }

    static public function update_multiple(array $params)
    {
        $result = [];
        $result['success'] = true;
        $model = new self();
        foreach ($params as $index => $item) {
            $id = $item[$model->getPrimaryKey()];
            $model = self::find($id);
            if (!$model)
                continue;
            $model->fill($item);
            $res = $model->save();
            $result["models"][] = $res;
            if (!$res['success'])
                $result['success'] = false;
        }
        return $result;
    }

    public function show($params, $id)
    {
        $query = $this->query();
        if (isset($params['relations'])) {
            $query = $this->relations($query, $params['relations']);
        }
        if (isset($params['select'])) {
            $query = $query->select($params['select']);
        }
        return $query->findOrFail($id);
    }

    static public function destroy_model($id)
    {
        $model = self::query()->findOrFail($id);
        $result = [];
        $result['success'] = true;
        $result['model'] = $model;
        if (!$model->destroy($id))
            $result['success'] = false;
        return $result;
    }

    public function belongsToMongo($related, $foreignKey = null, $ownerKey = null, $relation = null)
    {
        if (is_null($relation)) {
            $relation = $this->guessBelongsToRelation();
        }
        $instance = $this->newRelatedInstance($related);
        if (is_null($foreignKey)) {
            $foreignKey = Str::snake($relation) . '_' . $instance->getKeyName();
        }
        $ownerKey = $ownerKey ?: $instance->getKeyName();
        return new MongoBelongTo(
            $instance->newQuery(), $this, $foreignKey, $ownerKey, $relation
        );
    }

    public function hasManyMongo($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return new  MongoHasMany(
            $instance->newQuery(), $this, $foreignKey, $localKey
        );
    }

    public function hasOneMongo($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasOne($instance->newQuery(), $this, $instance->getTable() . '.' . $foreignKey, $localKey);
    }
}







