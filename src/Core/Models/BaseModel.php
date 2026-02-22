<?php

namespace Ronu\RestGenericClass\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Ronu\RestGenericClass\Core\Extension\Eloquent\Relations\MongoBelongTo;
use Ronu\RestGenericClass\Core\Extension\Eloquent\Relations\MongoHasMany;

class BaseModel extends Model
{
    use ValidatesRequests, HasFactory, Notifiable;

    /**
     * Current operation scenario (create/update).
     */
    protected string $scenario = "create";

    /**
     * The name of the model name parameters.
     */
    const MODEL = '';

    /**
     * Default columns for the model.
     */
    const columns = [];

    /**
     * Reference column for update operations.
     * When null, falls back to the model's primary key.
     */
    protected string|int|null $fieldKeyUpdate = null;

    /**
     * Relations of entity.
     */
    const RELATIONS = [];

    /**
     * Parent class information for hierarchy.
     */
    const PARENT = [];

    /**
     * Field name for self-referencing hierarchy (e.g., 'parent_id', 'role_id').
     * When defined, enables hierarchical listing with the `hierarchy` parameter.
     */
    const HIERARCHY_FIELD_ID = null;

    // =========================================================================
    // Role-Based Field Restriction
    // =========================================================================

    /**
     * Role-to-field restriction map.
     *
     * Declares which fields are "privileged" and which Spatie roles are allowed
     * to write them. Fields NOT listed in any role entry are considered base
     * fields and are writable by any authenticated user.
     *
     * This is the SINGLE source of truth for field-level access control.
     * Changing this array is the only action needed to update field restrictions
     * for this model.
     *
     * Format:
     *   [ 'spatie_role_name' => ['field1', 'field2', ...] ]
     *
     * Example:
     *   protected array $fieldsByRole = [
     *       'superadmin' => ['is_superuser', 'permissions'],
     *       'admin'      => ['status', 'role_id', 'is_verified'],
     *   ];
     *
     * An empty array (the default) means no field-level restrictions for
     * this model — backward compatible with all existing models.
     *
     * @var array<string, list<string>>
     */
    protected array $fieldsByRole = [];

    /**
     * Returns the list of fields the given user is NOT allowed to write,
     * derived from the $fieldsByRole map declared on this model.
     *
     * Consumed by:
     *  - FilterRequestByRole middleware  → strips denied fields from request payload
     *  - BaseRequest::mergeProhibitedRules() → adds 'prohibited' validation rules
     *
     * Resolution algorithm:
     *  1. $fieldsByRole is empty?          → [] (no restrictions — fast path)
     *  2. $user->is_superuser === true?    → [] (unrestricted access — fast path)
     *  3. universe = unique(flatten($fieldsByRole values))
     *  4. allowed  = union of $fieldsByRole[$role] for each role the user holds
     *  5. return universe − allowed
     *
     * Type note: $user is typed as mixed (not Authenticatable interface) because
     * hasRole() is provided by Spatie's HasRoles trait, which is not part of
     * Laravel's Authenticatable contract. A strict interface type hint would
     * require a custom contract — unnecessary complexity for this use case.
     *
     * @param  mixed $user  The currently authenticated Eloquent user model.
     * @return list<string> Field names the user cannot write.
     */
    public function getDeniedFieldsForUser(mixed $user): array
    {
        // Fast path: no restrictions declared on this model
        if (empty($this->fieldsByRole)) {
            return [];
        }

        // Fast path: superuser bypasses all field-level restrictions
        if ($user->is_superuser ?? false) {
            return [];
        }

        // Build the universe: every field mentioned across all roles
        $allPrivileged = array_unique(
            array_merge(...array_values($this->fieldsByRole))
        );

        // Build the allowed set: fields the user CAN write via their roles
        $allowed = [];
        foreach ($this->fieldsByRole as $role => $fields) {
            if (method_exists($user, 'hasRole') && $user->hasRole($role)) {
                $allowed = array_merge($allowed, $fields);
            }
        }

        // Denied = universe − allowed
        return array_values(array_diff($allPrivileged, $allowed));
    }

    // =========================================================================
    // Accessors / Mutators
    // =========================================================================

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function getFieldKeyUpdate(): string|int|null
    {
        return $this->fieldKeyUpdate ?? $this->getPrimaryKey();
    }

    public function getScenario(): string
    {
        return $this->scenario;
    }

    public function setScenario(string $scenario): void
    {
        $this->scenario = $scenario;
    }

    // =========================================================================
    // Hierarchy Helpers
    // =========================================================================

    public function hasHierarchy(): bool
    {
        return count(get_called_class()::PARENT) > 0;
    }

    public function hasHierarchyField(): bool
    {
        return defined(static::class . '::HIERARCHY_FIELD_ID')
            && static::HIERARCHY_FIELD_ID !== null;
    }

    public function getHierarchyFieldId(): ?string
    {
        return $this->hasHierarchyField() ? static::HIERARCHY_FIELD_ID : null;
    }

    public function hierarchyParent(): ?BelongsTo
    {
        if (!$this->hasHierarchyField()) {
            return null;
        }
        return $this->belongsTo(static::class, static::HIERARCHY_FIELD_ID);
    }

    public function hierarchyChildren(): HasMany
    {
        if (!$this->hasHierarchyField()) {
            throw new \RuntimeException(
                'Cannot use hierarchyChildren() without defining HIERARCHY_FIELD_ID in ' . static::class
            );
        }
        return $this->hasMany(static::class, static::HIERARCHY_FIELD_ID);
    }

    public function isHierarchyRoot(): bool
    {
        if (!$this->hasHierarchyField()) {
            return false;
        }
        return $this->{static::HIERARCHY_FIELD_ID} === null;
    }

    public function getHierarchyAncestors(): \Illuminate\Support\Collection
    {
        $ancestors = collect();
        if (!$this->hasHierarchyField()) {
            return $ancestors;
        }
        $parent = $this->hierarchyParent;
        while ($parent !== null) {
            $ancestors->push($parent);
            $parent = $parent->hierarchyParent;
        }
        return $ancestors;
    }

    public function getHierarchyDescendants(?int $maxDepth = null, int $currentDepth = 0): \Illuminate\Support\Collection
    {
        $descendants = collect();
        if (!$this->hasHierarchyField()) {
            return $descendants;
        }
        if ($maxDepth !== null && $currentDepth >= $maxDepth) {
            return $descendants;
        }
        foreach ($this->hierarchyChildren()->get() as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge(
                $child->getHierarchyDescendants($maxDepth, $currentDepth + 1)
            );
        }
        return $descendants;
    }

    // =========================================================================
    // Validation
    // =========================================================================

    protected function rules(string $scenario): array
    {
        return [];
    }

    public function self_validate(string $scenario = 'create', bool $specific = false, bool $validate_pk = true): array
    {
        $attrKeys      = array_keys($this->attributes);
        $originalsRules = $this->rules($scenario);
        $rules = array_filter($originalsRules, function ($v, $k) use ($attrKeys) {
            return in_array($k, $attrKeys) || (is_array($v)
                    ? array_search('required', $v)
                    : str_contains($v, 'required'));
        }, ARRAY_FILTER_USE_BOTH);

        if (!$validate_pk) {
            unset($rules[$this->getPrimaryKey()]);
        }
        if ($specific) {
            $rules = array_intersect_key($rules, $this->attributes);
        }

        $valid = $this->getValidationFactory()->make($this->attributes, $rules);
        return [
            'success' => !$valid->fails(),
            'errors'  => $valid->errors()->getMessages(),
            'model'   => get_called_class(),
        ];
    }

    public function save_model(array $attributes = [], string $scenario = 'create'): array
    {
        $parent = null;
        $this->setScenario($scenario);

        if (count($attributes) === 0) {
            $attributes = $this->attributes;
        }

        if (isset($attributes[$this->getPrimaryKey()]) && $scenario === 'create') {
            $this->setScenario('update');
        }

        $validate_all = $this->validate_all($attributes, $this->getScenario());
        if (!$validate_all['success']) {
            return $validate_all;
        }

        if (count(self::PARENT) > 0) {
            $parent = $this->save_parents($this->modelClass, $attributes, $this->getScenario());
        }

        if ($parent) {
            $attributes[$this->getPrimaryKey()] = $parent[$this->getPrimaryKey()];
        }

        $this->fill($attributes);
        $this->save();

        return ['success' => true, 'model' => $this->getAttributes()];
    }

    public function save_parents(mixed $attributes = null, string $scenario = 'create', bool $specific = false): mixed
    {
        $parent = null;
        if ($this->hasHierarchy()) {
            $parent_class = self::PARENT['class'];
            $parent = isset($attributes[$this->getPrimaryKey()])
                ? $parent_class::find($attributes[$this->getPrimaryKey()])
                : new $parent_class();

            $parent->fill($attributes);
            $parent->save();

            if ($parent->hasHierarchy()) {
                $parent->save_parents($parent, $attributes, $scenario, $specific);
            }
        }
        return $parent;
    }

    public function validate_all(array $attributes, string $scenario = 'create', bool $specific = false): array
    {
        $validate = [];

        if (isset($attributes[$this->getPrimaryKey()]) && $scenario === 'create') {
            $scenario = 'update';
        }

        $this->setScenario($scenario);

        if (count(self::PARENT) > 0) {
            $parent_class = self::PARENT['class'];
            $parent = isset($attributes[$this->getPrimaryKey()])
                ? $parent_class::find($attributes[$this->getPrimaryKey()])
                : new $parent_class();

            if (!$parent) {
                return ['success' => false, 'error' => 'Element not found', 'model' => $parent_class];
            }

            $validateparents = $this->parents_validate($attributes, $this->getScenario(), $specific);
            if ($validateparents) {
                $validate[] = $validateparents;
            }
        }

        $this->fill($attributes);
        $valid = $this->self_validate($this->getScenario(), $specific, false);

        if ($valid['success'] && count($validate) === 0) {
            return ['success' => true, 'error' => []];
        }

        if (!$valid['success']) {
            $validate[] = $valid;
        }

        return ['success' => false, 'errors' => $validate];
    }

    private function parents_validate(array $attributes, string $scenario = null, bool $specific = false): ?array
    {
        $result  = null;
        $parents = $this->get_parents($attributes, $scenario, $specific);

        foreach ($parents as $p) {
            if (count($p['errors']) > 0) {
                $result['success'] = false;
                $result['errors']  = $p['errors'];
                $result['model']   = $p['model'];
            }
        }

        return $result;
    }

    public function get_parents(array $attributes = null, string $scenario = 'create', bool $specific = false): array
    {
        $parent_array = [];

        if ($this->hasHierarchy()) {
            $parent_class    = self::PARENT['class'];
            $parent          = new $parent_class();
            $parent->fill($attributes);
            $parent_validate = $parent->self_validate($scenario, $specific);

            if ($parent->hasHierarchy()) {
                $parent_array = $parent->get_parents($parent, $attributes, $scenario, $specific);
            }

            $parent_array[] = $parent_validate;
        }

        return $parent_array;
    }

    static public function create_model(array $params): array
    {
        if (isset($params[self::MODEL]) || array_key_exists(0, $params)) {
            return self::save_array($params[self::MODEL]);
        }

        $class_model = new self();
        $class_model->fill($params);
        return $class_model->save($params);
    }

    static public function save_array(array $attributes, string $scenario = 'create'): array
    {
        $result = ['success' => true];

        foreach ($attributes as $model) {
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

    static public function update_multiple(array $params): array
    {
        $result = ['success' => true];
        $model  = new self();

        foreach ($params as $item) {
            $id    = $item[$model->getPrimaryKey()];
            $model = self::find($id);

            if (!$model) {
                continue;
            }

            $model->fill($item);
            $res = $model->save();

            $result['models'][] = $res;

            if (!$res['success']) {
                $result['success'] = false;
            }
        }

        return $result;
    }

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

    static public function destroy_model(mixed $id): array
    {
        $model  = self::query()->findOrFail($id);
        $result = ['success' => true, 'model' => $model];

        if (!$model->destroy($id)) {
            $result['success'] = false;
        }

        return $result;
    }

    // =========================================================================
    // MongoDB Relation Helpers
    // =========================================================================
    /**
     * Create a belongs-to relationship with MongoDB
     * @param string $related Related model class name
     * @param string|null $foreignKey Foreign key name
     * @param string|null $ownerKey Owner key name
     * @param string|null $relation Relation name
     * @return MongoBelongTo The relationship instance
     */
    public function belongsToMongo(
        string  $related,
        ?string $foreignKey = null,
        ?string $ownerKey   = null,
        ?string $relation   = null
    ): MongoBelongTo {
        if (is_null($relation)) {
            $relation = $this->guessBelongsToRelation();
        }

        $instance   = $this->newRelatedInstance($related);
        $foreignKey = $foreignKey ?? (Str::snake($relation) . '_' . $instance->getKeyName());
        $ownerKey   = $ownerKey   ?? $instance->getKeyName();

        return new MongoBelongTo($instance->newQuery(), $this, $foreignKey, $ownerKey, $relation);
    }

    /**
     * Create a has-many relationship with MongoDB
     * @param string $related Related model class name
     * @param string|null $foreignKey Foreign key name
     * @param string|null $localKey Local key name
     * @return MongoHasMany The relationship instance
     */
    public function hasManyMongo(
        string  $related,
        ?string $foreignKey = null,
        ?string $localKey   = null
    ): MongoHasMany|HasMany {
        $instance   = $this->newRelatedInstance($related);
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey   = $localKey   ?? $this->getKeyName();

        return new MongoHasMany($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    /**
     * Create a has-one relationship with MongoDB
     * @param string $related Related model class name
     * @param string|null $foreignKey Foreign key name
     * @param string|null $localKey Local key name
     * @return HasOneOrMany The relationship instance
     */
    public function hasOneMongo(
        string  $related,
        ?string $foreignKey = null,
        ?string $localKey   = null
    ): HasOneOrMany {
        $instance   = $this->newRelatedInstance($related);
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey   = $localKey   ?? $this->getKeyName();

        return $this->newHasOne(
            $instance->newQuery(),
            $this,
            $instance->getTable() . '.' . $foreignKey,
            $localKey
        );
    }
}