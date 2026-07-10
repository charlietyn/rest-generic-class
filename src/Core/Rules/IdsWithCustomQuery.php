<?php

namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use Ronu\RestGenericClass\Core\Traits\ValidatesExistenceInDatabase;
use Ronu\RestGenericClass\Core\Validation\ValidationRuleSupport;

class IdsWithCustomQuery implements ValidationRule, ValidatorAwareRule
{
    use ValidatesExistenceInDatabase;

    protected Validator $validator;

    public function __construct(
        string            $connection,
        protected Closure $queryCallback,
        protected string  $column = 'id',
        protected array   $additionalConditions = [],
        protected ?string $inputKey = null,
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
            $this->inputKey ?? $this->column,
            $this->column
        );

        if ($ids === null) {
            return;
        }

        $validated = $this->validateIdsWithCustomQuery($ids, $this->queryCallback, $this->column, $this->additionalConditions);
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
