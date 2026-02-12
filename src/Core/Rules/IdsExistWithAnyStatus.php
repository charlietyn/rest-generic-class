<?php

namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use Ronu\RestGenericClass\Core\Traits\ValidatesExistenceInDatabase;

class IdsExistWithAnyStatus implements ValidationRule, ValidatorAwareRule
{
    use ValidatesExistenceInDatabase;

    protected Validator $validator;

    public function __construct(
        string           $connection,
        protected string $table,
        protected array  $statuses,
        protected string $column = 'status',
        protected array  $additionalConditions = [],
    )
    {
        $this->connection = $connection;
    }


    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;
        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value) || empty($value)) {
            return;
        }
        $ids = array_filter($value, fn($id) => $id !== null && $id !== '');
        $validated = $this->validateIdsExistWithAnyStatus($ids, $this->table, $this->statuses, $this->column, $this->additionalConditions);
        if (!$validated) {
            $this->validator->errors()->add(
                $attribute,
                'The following IDs do not exist: ' . implode(', ', $ids)
            );
        }
    }
}