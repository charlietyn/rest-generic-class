<?php
declare(strict_types=1);
namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ArrayCount Rule
 *
 * Validates that an array has between $min and $max elements.
 * Supports both bounds, only minimum, or only maximum.
 *
 * Usage:
 *   new ArrayCount(min: 2, max: 5)   // between 2 and 5
 *   new ArrayCount(min: 1)            // at least 1
 *   new ArrayCount(max: 10)           // at most 10
 *   new ArrayCount(min: 3, max: 3)    // exactly 3
 */
class ArrayCount implements ValidationRule
{
    public function __construct(
        private readonly ?int $min = null,
        private readonly ?int $max = null,
    ) {
        // Guard: at least one bound must be provided
        if ($this->min === null && $this->max === null) {
            throw new \InvalidArgumentException('ArrayCount requires at least a min or max value.');
        }

        // Guard: min cannot exceed max
        if ($this->min !== null && $this->max !== null && $this->min > $this->max) {
            throw new \InvalidArgumentException("ArrayCount: min ({$this->min}) cannot be greater than max ({$this->max}).");
        }
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail("The :attribute must be an array.");
            return;
        }

        $count = count($value);

        // Exactly N elements
        if ($this->min !== null && $this->max !== null && $this->min === $this->max) {
            if ($count !== $this->min) {
                $fail("The :attribute must contain exactly {$this->min} " . str('item')->plural($this->min) . ".");
            }
            return;
        }

        // Between min and max
        if ($this->min !== null && $this->max !== null) {
            if ($count < $this->min || $count > $this->max) {
                $fail("The :attribute must contain between {$this->min} and {$this->max} items.");
            }
            return;
        }

        // Only minimum
        if ($this->min !== null && $count < $this->min) {
            $fail("The :attribute must contain at least {$this->min} " . str('item')->plural($this->min) . ".");
            return;
        }

        // Only maximum
        if ($this->max !== null && $count > $this->max) {
            $fail("The :attribute must not exceed {$this->max} " . str('item')->plural($this->max) . ".");
        }
    }
}