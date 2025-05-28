<?php

namespace Ronu\RestGenericClass\Core\Requests;

use Illuminate\Foundation\Validation\ValidatesRequests;

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
    use ValidatesRequests;

    /**
     * The name of the entity associated with the request.
     *
     * @var string
     */
    protected string $entity_name = "";

    /**
     * Parses validation rules based on the current scenario.
     *
     * @param string $path The file path where the validation rules are defined.
     * @return array The validation rules for the current scenario.
     */
    public function parseRules(string $path): array
    {
        $scenario = $this->getScenario();
        $rules = include($path);
        $rules['query'] = $this->query_rules();
        if (!array_key_exists($scenario, $rules)) {
            throw new \Exception("Scenario '$scenario' not found in validation rules.");
        }
        return $rules[$scenario];
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
        $scenario = array_key_exists('_scenario', $this->all()) ? $this->all()['_scenario'] : null;
        if (!$scenario)
            $scenario = $this->conditionalScenario($this->method());
        return $scenario;
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
}