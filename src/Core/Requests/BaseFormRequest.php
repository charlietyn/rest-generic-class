<?php

namespace Ronu\RestGenericClass\Core\Requests;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;
use Ronu\RestGenericClass\Core\Traits\ValidatesExistenceInDatabase;
use Illuminate\Support\Facades\DB;


/**
 * Class BaseFormRequest
 *
 * This class extends Laravel's FormRequest and provides additional functionality
 * for handling request validation and scenario-based rule parsing in RESTful APIs.
 *
 * @package Ronu\RestGenericClass\Core\Requests
 */
class BaseFormRequest extends \Illuminate\Foundation\Http\FormRequest
{
    use ValidatesRequests,ValidatesExistenceInDatabase;


    /**
     * The name of the entity associated with the request.
     *
     * @var string
     */
    protected string $entity_name = "";

    protected bool $has_escenario = true;

    /**
     * Deferred message callbacks to run after the Validator is built.
     *
     * Each item is a Closure(Validator): void
     *
     * @var array<int, \Closure>
     */
    private array $pendingValidatorCallbacks = [];


    /**
     * Parses validation rules based on the current scenario.
     *
     * @param string $path The file path where the validation rules are defined.
     * @return array The validation rules for the current scenario.
     */
    public function parseRules(string $path): array
    {
        $rules = [];
        if ($this->has_escenario) {
            $scenario = $this->getScenario();
            $rules = include($path);
            $rules['query'] = $this->query_rules();
            if (!array_key_exists($scenario, $rules)) {
                throw new \Exception("Scenario '$scenario' not found in validation rules.");
            }
            return $rules[$scenario];
        }
        return $rules;
    }

    /**
     * Determines the current scenario for the request.
     *
     * If the `_scenario` key is present in the request data, it is used.
     * Otherwise, the scenario is determined based on the HTTP method.
     *
     * @return string|null The current scenario.
     */
    public function getScenario(): ?string
    {
        if ($this->has_escenario) {
            $scenario = array_key_exists('_scenario', $this->all()) ? $this->all()['_scenario'] : null;
            if (!$scenario)
                $scenario = $this->conditionalScenario($this->method());
            return $scenario;
        }
        return null;
    }

    /**
     * Defines the validation rules for the request.
     *
     * This method should be overridden in child classes to provide specific validation rules.
     *
     * @return array An array of validation rules.
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Determines the scenario based on the HTTP method and request content.
     *
     * @param string $method The HTTP method (e.g., POST, GET, etc.).
     * @return string The scenario determined by the HTTP method and request content.
     */
    public function conditionalScenario(string $method): string
    {
        if ($this->has_escenario) {
            $scenario = $method;
            $conditional = [
                'POST' => "create",
                'GET' => "query",
                'DELETE' => "query",
                'PUT' => "update",
                'PATCH' => "update",
                'DEFAULT' => "create",
                'BULK_CREATE' => "bulk_create",
                'BULK_UPDATE' => "bulk_update",
            ];
            $content = (array)json_decode(request()->getContent());
            if ($method == 'POST' && count($content) == 1 && array_key_first($content) === $this->entity_name && substr_count(request()->getPathInfo(), '/') == 2)
                $scenario = 'BULK_CREATE';
            elseif (str_ends_with(request()->decodedPath(), '/update_multiple')) {
                $scenario = 'BULK_UPDATE';
            }
            return $conditional[$scenario];
        }
        return "";
    }

    /**
     * Defines validation rules for query parameters.
     *
     * This method can be overridden to provide specific query validation rules.
     *
     * @return array An array of validation rules for query parameters.
     */
    private function query_rules(): array
    {
        return [];
    }

    /**
     * Validates the request data.
     *
     * If the `_specific` key is present in the request data, only the rules
     * for the specified keys are applied. Otherwise, all rules are applied.
     *
     * @return void
     */
    public function validate_request(): void
    {
        $specific = isset($this->all()['_specific']);
        $attributes = $this->all();
        $rules = $specific ? array_intersect_key($this->rules(), $attributes) : $this->rules();
        $this->validate($this, $rules, $this->messages(), $attributes);
    }

    /**
     * Get rules for specific scenario (used by OpenAPI generator)
     *
     * @param string $scenario Scenario name
     * @return array Validation rules
     */
    public function getRulesForScenario(string $scenario): array
    {
        $rules = $this->getAllRules();
        $scenario = $scenario == 'validate' ? 'create' : $scenario;
        return $this->has_escenario && isset($rules[$scenario])?$rules[$scenario] : [];
    }

    /**
     * Get all available scenarios
     *
     * @return array Scenario names
     */
    public function getAvailableScenarios(): mixed
    {
        return $this->has_escenario ? array_keys($this->getAllRules()) : true;
    }

    /**
     * Get all rules
     *
     * @return array of rules
     */
    public function getAllRules(): array
    {
        return include($this->pathRules());
    }

    /**
     * Get the path to the rules file.
     * @return string
     */
    public function pathRules(): string
    {
        return "";
    }

    /**
     * Validate IDs with relationship constraint
     * Ensures IDs exist and belong to a specific parent resource
     *
     * @param array<int|string> $ids
     * @param string $table Table name
     * @param string $relationColumn Relation column name (e.g., 'department_id')
     * @param int|string $relationValue Relation value to match
     * @param array<string, mixed> $additionalConditions Additional conditions
     * @return bool
     */
    protected function validateIdsWithRelation(
        array $ids,
        string $table,
        string $relationColumn,
        int|string $relationValue,
        array $additionalConditions = []
    ): bool {
        $conditions = array_merge(
            [$relationColumn => $relationValue],
            $additionalConditions
        );

        return $this->validateIdsExistInTable($ids, $table, 'id', $conditions);
    }

    /**
     * Validate unique values in database excluding current record
     * Useful for update operations
     *
     * @param string $value Value to check
     * @param string $table Table name
     * @param string $column Column to check
     * @param int|string|null $excludeId ID to exclude from check
     * @param array<string, mixed> $additionalConditions Additional conditions
     * @return bool
     */
    protected function validateUnique(
        string $value,
        string $table,
        string $column,
        int|string|null $excludeId = null,
        array $additionalConditions = []
    ): bool {
        try {
            $query = DB::table($table)->where($column, $value);
            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }
            foreach ($additionalConditions as $col => $val) {
                $query->where($col, $val);
            }

            return $query->doesntExist();
        } catch (\Exception $e) {
            Log::channel('rest-generic-class')->error('Unique validation error', [
                'table' => $table,
                'column' => $column,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get missing IDs with formatted error message
     * Convenience method for building error messages
     *
     * @param array<int|string> $ids
     * @param string $table
     * @param string $resourceName Human-readable resource name
     * @param array<string, mixed> $conditions
     * @return string|null Error message or null if all IDs exist
     */
    protected function getMissingIdsMessage(
        array $ids,
        string $table,
        string $resourceName,
        array $conditions = []
    ): ?string {
        $missing = $this->getMissingIds($ids, $table, 'id', $conditions);

        if (empty($missing)) {
            return null;
        }

        return sprintf(
            'The following %s IDs do not exist or are invalid: %s',
            $resourceName,
            implode(', ', $missing)
        );
    }

    /**
     * Get input with default value if null or empty
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getWithDefault(string $key, mixed $default = null): mixed
    {
        $value = $this->input($key);

        return ($value === null || $value === '') ? $default : $value;
    }
    /**
     * Check if request is for creating a new resource
     *
     * @return bool
     */
    protected function isCreating(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * Check if request is for updating an existing resource
     *
     * @return bool
     */
    protected function isUpdating(): bool
    {
        return in_array($this->method(), ['PUT', 'PATCH'], true);
    }

    /**
     * Get the route parameter value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getRouteParameter(string $key, mixed $default = null): mixed
    {
        return $this->route($key, $default);
    }

    /**
     * Merge additional data into validated data
     * Useful for adding computed or default values
     *
     * @param array<string, mixed> $additionalData
     * @return array<string, mixed>
     */
    public function validatedWith(array $additionalData): array
    {
        return array_merge($this->validated(), $additionalData);
    }

    /**
     * Register a deferred validation message or callback.
     *
     * Accepts three shapes:
     *
     *   // 1. Single field + message
     *   $this->addMessageValidator('users', 'Missing user ids: 5, 8');
     *
     *   // 2. Multiple fields at once
     *   $this->addMessageValidator([
     *       'users'   => 'Missing user ids: 5, 8',
     *       'address' => 'Invalid address',
     *   ]);
     *
     *   // 3. Full control via Closure — runs after Validator is built
     *   $this->addMessageValidator(function (Validator $validator) {
     *       $ids = array_column($this->input('users', []), 'id');
     *       $missing = array_diff($ids, DB::table('users')->pluck('id')->all());
     *       if (!empty($missing)) {
     *           $validator->errors()->add(
     *               'users',
     *               'The following user ids do not exist: ' . implode(', ', $missing)
     *           );
     *       }
     *   });
     *
     * @param string|array|\Closure $fieldOrMessages
     * @param string|null           $message  Used only when $fieldOrMessages is a string
     */
    public function addMessageValidator(string|array|\Closure $fieldOrMessages, ?string $message = null): void
    {
        if ($fieldOrMessages instanceof \Closure) {
            // Shape 3 — raw Closure, receives Validator instance
            $this->pendingValidatorCallbacks[] = $fieldOrMessages;
            return;
        }

        if (is_string($fieldOrMessages)) {
            // Shape 1 — single field + message
            $field = $fieldOrMessages;
            $this->pendingValidatorCallbacks[] = static function (Validator $v) use ($field, $message): void {
                $v->errors()->add($field, $message ?? 'Validation failed.');
            };
            return;
        }

        // Shape 2 — ['field' => 'message', ...] or ['field' => ['msg1', 'msg2']]
        $map = $fieldOrMessages;
        $this->pendingValidatorCallbacks[] = static function (Validator $v) use ($map): void {
            foreach ($map as $field => $messages) {
                foreach ((array) $messages as $msg) {
                    $v->errors()->add($field, $msg);
                }
            }
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Laravel hook — flushes all pending callbacks after Validator is built
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Hook called by Laravel after the Validator instance is created.
     *
     * Flushes all pending callbacks registered via addMessageValidator().
     * Each callback receives the fully-built Validator — safe to call errors()->add().
     */
    public function withValidator(Validator $validator): void
    {
        if (empty($this->pendingValidatorCallbacks)) {
            return;
        }

        $validator->after(function (Validator $v): void {
            foreach ($this->pendingValidatorCallbacks as $callback) {
                $callback($v);
            }
            // Clear after flush so re-validation doesn't re-apply them
            $this->pendingValidatorCallbacks = [];
        });
    }
}