<?php


namespace Ronu\RestGenericClass\Core\Requests;


use Illuminate\Foundation\Validation\ValidatesRequests;

class BaseFormRequest extends \Illuminate\Foundation\Http\FormRequest
{

    use ValidatesRequests;


    public function parseRules($path)
    {
        $scenario = $this->getScenario();
        $rules = include($path);
        $rules['query'] = $this->query_rules();
        return $rules[$scenario];
    }

    public function getScenario()
    {
        $scenario = array_key_exists('_scenario', $this->all()) ? $this->all()['_scenario'] : null;
        if (!$scenario)
            $scenario=$this->conditionalScenario($this->method());
        return $scenario;
    }

    public function rules(): array
    {
        return [];
    }

    public function conditionalScenario($method): string
    {
        $conditional= [
            'POST' => "create",
            'GET' => "query",
            'DELETE' => "query",
            'PUT' => "update",
            'PATCH' => "update",
            'DEFAULT' => "create",
        ];
        return  $conditional[$method];
    }

    private function query_rules()
    {
        return [];
    }

    public function validate_request()
    {
        $specific = isset($this->all()['_specific']);
        $attributes = $this->all();
        $rules = $specific ? array_intersect_key($this->rules(), $attributes) : $this->rules();
        $this->validate($this, $rules);
    }

}

