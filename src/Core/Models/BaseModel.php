<?php
/**Generate by ASGENS
 *@author Charlietyn
 */

namespace Ronu\RestGenericClass\Core\Models;

use App\Scopes\NonDeletedScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * Current operation scenario (create/update)
     * @var string
     */
    protected string $scenario = "create";

    /**
     * The name of the model name parameters
     *
     * @var string
     */
    const MODEL = '';

    /**
     * Default columns for the model
     * @var array
     */
    const columns=[];

    /**
     * Reference columns for update the model
     * @var string|integer|null
     */
    protected string|int|null $fieldKeyUpdate;
    /**
     * Relations of entity
     *
     * @var array
     */
    const RELATIONS = [];

    /**
     * Parent class information for hierarchy
     * @var array
     */
    const PARENT = [];

    /**
     * Get the primary key for the model
     * @return string The primary key name
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get the reference column for update model
     * @return string The field name
     */

    public function getFieldKeyUpdate(): string|int|null
    {
        return $this->fieldKeyUpdate;
    }
    /**
     * Get the current scenario (create/update)
     * @return string The current scenario
     */
    public function getScenario()
    {
        return $this->scenario;
    }

    /**
     * Set the scenario for the model operations
     * @param string $scenario The scenario to set (create/update)
     */
    public function setScenario($scenario): void
    {
        $this->scenario = $scenario;
    }

    /**
     * Check if the model has a hierarchy (parent classes)
     * @return bool True if model has parent classes, false otherwise
     */
    public function hasHierarchy()
    {
        return count(get_called_class()::PARENT) > 0;
    }

    /**
     * Define validation rules for the model
     * @param string $scenario The scenario to get rules for
     * @return array The validation rules
     */
    protected function rules($scenario)
    {
        return [];
    }

    /**
     * Validate the current model instance
     * @param string $scenario The validation scenario
     * @param bool $specific Whether to validate only set attributes
     * @param bool $validate_pk Whether to validate the primary key
     * @return array Validation result with success flag and errors
     */
    public function self_validate(string $scenario = 'create', bool $specific = false, bool $validate_pk = true)
    {
        $attrKeys=array_keys($this->attributes);
        $originalsRules=$this->rules($scenario);
        $rules=array_filter($originalsRules, function($v,$k) use ($attrKeys) {
            return in_array($k, $attrKeys) || is_array($v)?array_search('required',$v):str_contains($v,'required');
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

    /**
     * Save the model with validation
     * @param array $attributes Attributes to save
     * @param string $scenario The operation scenario (create/update)
     * @return array Result with success flag and model data
     */
    public function save_model(array $attributes = [], string $scenario = 'create'): array
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

    /**
     * Save parent models in a hierarchy
     * @param mixed|null $attributes Model attributes to save
     * @param string $scenario The operation scenario
     * @param bool $specific Whether to validate only set attributes
     * @return mixed The saved parent model or null
     */
    public function save_parents(mixed $attributes = null, string $scenario = 'create', bool $specific = false): mixed
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

    /**
     * Validate model and all its parent models
     * @param array $attributes Attributes to validate
     * @param string $scenario The validation scenario
     * @param bool $specific Whether to validate only set attributes
     * @return array Result with success flag and errors
     */
    public function validate_all(array $attributes, string $scenario = 'create', bool $specific = false): array
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

    /**
     * Validate all parent models
     * @param array $attributes Attributes to validate
     * @param string|null $scenario The validation scenario
     * @param bool $specific Whether to validate only set attributes
     * @return array|null Validation errors or null if validation passes
     */
    private function parents_validate(array $attributes, string $scenario = null, bool $specific = false): ?array
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

    /**
     * Get all parent models with validation results
     * @param array|null $attributes Attributes to validate
     * @param string $scenario The validation scenario
     * @param bool $specific Whether to validate only set attributes
     * @return array Array of parent validation results
     */
    public function get_parents(array $attributes = null, string $scenario = 'create', bool $specific = false): array
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

    /**
     * Create a new model or multiple models
     * @param array $params Parameters for model creation
     * @return array Result with success flag and created models
     */
    static public function create_model(array $params): array
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

    /**
     * Save an array of models
     * @param array $attributes Array of model attributes
     * @param string $scenario The operation scenario
     * @return array Result with success flag and saved models
     */
    static public function save_array(array $attributes, $scenario = 'create'): array
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

    /**
     * Update multiple model instances
     * @param array $params Array of model attributes with primary keys
     * @return array Result with success flag and updated models
     */
    static public function update_multiple(array $params): array
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

    /**
     * Show a model with optional relations and selected attributes
     * @param array $params Query parameters (relations, select)
     * @param mixed $id The primary key of the model to show
     * @return mixed The found model instance
     */
    public function show(array $params, mixed $id): mixed
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

    /**
     * Delete a model by its primary key
     * @param mixed $id The primary key of the model to delete
     * @return array Result with success flag and deleted model
     */
    static public function destroy_model(mixed $id): array
    {
        $model = self::query()->findOrFail($id);
        $result = [];
        $result['success'] = true;
        $result['model'] = $model;
        if (!$model->destroy($id))
            $result['success'] = false;
        return $result;
    }

    /**
     * Create a belongs-to relationship with MongoDB
     * @param string $related Related model class name
     * @param string|null $foreignKey Foreign key name
     * @param string|null $ownerKey Owner key name
     * @param string|null $relation Relation name
     * @return MongoBelongTo The relationship instance
     */
    public function belongsToMongo(string $related, string $foreignKey = null, string $ownerKey = null, string $relation = null): MongoBelongTo
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

    /**
     * Create a has-many relationship with MongoDB
     * @param string $related Related model class name
     * @param string|null $foreignKey Foreign key name
     * @param string|null $localKey Local key name
     * @return MongoHasMany The relationship instance
     */
    public function hasManyMongo(string $related, string $foreignKey = null, string $localKey = null): MongoHasMany| HasMany
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return new MongoHasMany(
            $instance->newQuery(), $this, $foreignKey, $localKey
        );
    }

    /**
     * Create a has-one relationship with MongoDB
     * @param string $related Related model class name
     * @param string|null $foreignKey Foreign key name
     * @param string|null $localKey Local key name
     * @return HasOneOrMany The relationship instance
     */
    public function hasOneMongo(string $related, string $foreignKey = null, string $localKey = null): HasOneOrMany
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasOne($instance->newQuery(), $this, $instance->getTable() . '.' . $foreignKey, $localKey);
    }
}