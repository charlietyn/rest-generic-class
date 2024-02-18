<?php


namespace Ronu\Core\Requests\RestGenericClass;


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
            switch ($this->method()) {
                case 'POST':
                    $scenario = 'create';
                    break;
                case 'GET'||'DELETE':
                    $scenario = 'query';
                    break;
                case 'PUT' || 'PATCH':
                    $scenario = 'update';
                    break;
                default:
                    $scenario = 'create';
            }
        return $scenario;
    }

    public function rules(): array
    {
        return [];
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

