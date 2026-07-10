<?php

namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use Ronu\RestGenericClass\Core\Traits\ValidatesExistenceInDatabase;
use Ronu\RestGenericClass\Core\Validation\ValidationRuleSupport;

class IdsExistInTable implements ValidationRule, ValidatorAwareRule
{
    use ValidatesExistenceInDatabase;

    protected Validator $validator;

    public function __construct(
        string           $connection,
        protected string $table,
        protected string $column = 'id',
        protected array  $additionalConditions = [],
        protected ?string $inputKey = null,
        protected ?string $softDeleteColumn = null,
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

        // When a soft-delete column is provided, exclude soft-deleted rows so
        // a value belonging to a deleted row is treated as non-existent.
        if ($this->softDeleteColumn !== null) {
            $valid = $this->validateIdsExistNotDeleted(
                $ids, $this->table, $this->column, $this->additionalConditions, $this->softDeleteColumn
            );
            if (!$valid) {
                ValidationRuleSupport::addMissingIdsError(
                    $this->validator,
                    $attribute,
                    $ids,
                    $this->additionalConditions
                );
            }
            return;
        }

        $validated = $this->validateIdsExistInTable($ids, $this->table, $this->column, $this->additionalConditions);
        if (!$validated['success']) {
            ValidationRuleSupport::addMissingIdsError(
                $this->validator,
                $attribute,
                $validated['missing_ids'],
                $this->additionalConditions
            );
        }
    }
}
