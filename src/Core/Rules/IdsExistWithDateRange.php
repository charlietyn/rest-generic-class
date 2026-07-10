<?php

namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use Ronu\RestGenericClass\Core\Traits\ValidatesExistenceInDatabase;
use Ronu\RestGenericClass\Core\Validation\ValidationRuleSupport;

class IdsExistWithDateRange implements ValidationRule, ValidatorAwareRule
{
    use ValidatesExistenceInDatabase;

    protected Validator $validator;

    public function __construct(
        string           $connection,
        protected string $table,
        protected string $dateColumn,
        protected ?string $startDate = null,
        protected ?string $endDate = null,
        protected array $additionalConditions = [],
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
        $items = ValidationRuleSupport::normalizeValue($value);

        if ($items === null) {
            return;
        }

        $ids = ValidationRuleSupport::extractIds($items);
        $validated = $this->validateIdsExistWithDateRange($ids, $this->table, $this->dateColumn, $this->startDate, $this->endDate, $this->additionalConditions);
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
