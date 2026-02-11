<?php
declare(strict_types=1);
namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * ArrayCount Rule
 *
 * Validates that an array has between $min and $max elements.
 *
 * Root cause fix: Laravel's FormatsMessages::replaceAttributePlaceholder()
 * calls Str::upper($value) where $value is the raw input — when that input
 * is an array, PHP throws "Array to string conversion".
 *
 * Solution: resolve the human-readable attribute name inside the Closure
 * and inject it directly into the message string, so Laravel receives a
 * fully-formed message with NO remaining :attribute placeholders to replace.
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
     * Run the validation rule.
     *
     * We intentionally resolve the human-readable attribute name here and
     * embed it in the final string before calling $fail(), so that Laravel's
     * replaceAttributePlaceholder() receives a message that has NO :attribute
     * token left — avoiding the Array-to-string conversion crash.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Convert dot-notation key (e.g. "user.address_ids") to human label
        $label = $this->resolveLabel($attribute);

        if (! is_array($value)) {
            $fail("The {$label} must be an array.");
            return;
        }

        $count = count($value);

        // Exactly N elements
        if ($this->min !== null && $this->max !== null && $this->min === $this->max) {
            if ($count !== $this->min) {
                $noun = Str::plural('item', $this->min);
                $fail("The {$label} must contain exactly {$this->min} {$noun}.");
            }
            return;
        }

        // Between min and max
        if ($this->min !== null && $this->max !== null) {
            if ($count < $this->min || $count > $this->max) {
                $fail("The {$label} must contain between {$this->min} and {$this->max} items.");
            }
            return;
        }

        // Only minimum
        if ($this->min !== null && $count < $this->min) {
            $noun = Str::plural('item', $this->min);
            $fail("The {$label} must contain at least {$this->min} {$noun}.");
            return;
        }

        // Only maximum
        if ($this->max !== null && $count > $this->max) {
            $noun = Str::plural('item', $this->max);
            $fail("The {$label} must not exceed {$this->max} {$noun}.");
        }
    }

    /**
     * Convert a dot-notation attribute key to a readable label.
     *
     * "address_ids"        → "address ids"
     * "user.address_ids"   → "address ids"  (only the last segment)
     */
    private function resolveLabel(string $attribute): string
    {
        $segment = Str::afterLast($attribute, '.');

        return str_replace('_', ' ', $segment);
    }
}