<?php

namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use Ronu\RestGenericClass\Core\Traits\ValidatesExistenceInDatabase;
use Ronu\RestGenericClass\Core\Validation\ValidationRuleSupport;

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
        $ids = ValidationRuleSupport::extractIdsOrAddError(
            $this->validator,
            $attribute,
            $value,
            'id',
            $this->column
        );

        if ($ids === null) {
            return;
        }

        $validated = $this->validateIdsExistWithAnyStatus($ids, $this->table, $this->statuses, $this->column, $this->additionalConditions);
        if (!$validated) {
            ValidationRuleSupport::addMissingIdsError(
                $this->validator,
                $attribute,
                $ids,
                $this->additionalConditions
            );
        }
    }
}
