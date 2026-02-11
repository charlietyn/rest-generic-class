<?php
declare(strict_types=1);
namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use Illuminate\Support\Str;

/**
 * ArrayCount Rule
 *
 * Validates that an array has between $min and $max elements.
 *
 * WHY ValidatorAwareRule:
 * Laravel's FormatsMessages::makeReplacements() calls getDisplayableValue()
 * which eventually calls Str::upper($value) — when $value is an array that
 * method throws "Array to string conversion".
 *
 * This happens NOT because of our $fail() message, but because the Validator
 * internally formats ALL failed rules for a given attribute, and somewhere in
 * that pipeline it tries to stringify the raw array value.
 *
 * Fix: implement ValidatorAwareRule to get a reference to the Validator
 * instance, then push messages directly into the MessageBag — bypassing
 * the makeReplacements() pipeline entirely. The message arrives final,
 * with NO placeholders for Laravel to touch.
 *
 * Usage:
 *   new ArrayCount(min: 2, max: 5)   // between 2 and 5
 *   new ArrayCount(min: 1)            // at least 1
 *   new ArrayCount(max: 10)           // at most 10
 *   new ArrayCount(min: 3, max: 3)    // exactly 3
 */
class ArrayCount implements ValidationRule, ValidatorAwareRule
{
    private Validator $validator;

    public function __construct(
        private readonly ?int $min = null,
        private readonly ?int $max = null,
    ) {
        if ($this->min === null && $this->max === null) {
            throw new \InvalidArgumentException('ArrayCount requires at least a min or max value.');
        }

        if ($this->min !== null && $this->max !== null && $this->min > $this->max) {
            throw new \InvalidArgumentException(
                "ArrayCount: min ({$this->min}) cannot be greater than max ({$this->max})."
            );
        }
    }

    /**
     * Set the current validator instance.
     * Called automatically by Laravel when ValidatorAwareRule is implemented.
     */
    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * Run the validation rule.
     *
     * Messages are pushed directly into the Validator's MessageBag —
     * zero placeholders — so FormatsMessages never touches the raw array value.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $label = $this->resolveLabel($attribute);

        if (! is_array($value)) {
            $this->addError($attribute, "The {$label} must be an array.");
            return;
        }

        $count = count($value);

        // Exactly N elements
        if ($this->min !== null && $this->max !== null && $this->min === $this->max) {
            if ($count !== $this->min) {
                $noun = Str::plural('item', $this->min);
                $this->addError($attribute, "The {$label} must contain exactly {$this->min} {$noun}.");
            }
            return;
        }

        // Between min and max
        if ($this->min !== null && $this->max !== null) {
            if ($count < $this->min || $count > $this->max) {
                $this->addError(
                    $attribute,
                    "The {$label} must contain between {$this->min} and {$this->max} items."
                );
            }
            return;
        }

        // Only minimum
        if ($this->min !== null && $count < $this->min) {
            $noun = Str::plural('item', $this->min);
            $this->addError($attribute, "The {$label} must contain at least {$this->min} {$noun}.");
            return;
        }

        // Only maximum
        if ($this->max !== null && $count > $this->max) {
            $noun = Str::plural('item', $this->max);
            $this->addError($attribute, "The {$label} must not exceed {$this->max} {$noun}.");
        }
    }

    /**
     * Push a fully-formed message directly into the Validator's MessageBag.
     *
     * errors()->add() skips the entire makeReplacements() pipeline —
     * the message arrives final with no tokens left to process.
     */
    private function addError(string $attribute, string $message): void
    {
        $this->validator->errors()->add($attribute, $message);
    }

    /**
     * Convert dot-notation attribute key to a human-readable label.
     *
     * "address_ids"       → "address ids"
     * "user.address_ids"  → "address ids"
     */
    private function resolveLabel(string $attribute): string
    {
        $segment = Str::afterLast($attribute, '.');

        return str_replace('_', ' ', $segment);
    }
}