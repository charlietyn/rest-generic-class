<?php

namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use Ronu\RestGenericClass\Core\Traits\ValidatesExistenceInDatabase;

class IdsExistInTable implements ValidationRule, ValidatorAwareRule
{
    use ValidatesExistenceInDatabase;

    protected Validator $validator;

    public function __construct(
        string           $connection,
        protected string $table,
        protected string $column = 'id',
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
        if ($value === null || $value === '') {
            return;
        }
        if (!is_array($value)) {
            $value = [$value];
        }
        if (empty($value)) {
            return;
        }
        $ids = array_filter($value, fn($id) => $id !== null && $id !== '');
        $validated = $this->validateIdsExistInTable($ids, $this->table, $this->column, $this->additionalConditions);
        if (!$validated['success']) {
            $this->validator->errors()->add(
                $attribute,
                'The following IDs do not exist: ' . implode(', ', $validated['missing_ids'])
                . $this->buildConditionsMessage($this->additionalConditions)
            );
        }
    }
}