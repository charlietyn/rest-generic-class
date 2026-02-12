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
 * Validates that an array contains between $min and $max elements.
 *
 * ──────────────────────────────────────────────────────────────────
 * CUSTOM MESSAGE — 3 LEVELS (all optional, lowest index wins):
 * ──────────────────────────────────────────────────────────────────
 *
 * Level 1 — single message for every failure scenario:
 *   new ArrayCount(max: 1, message: 'Only one address is allowed.')
 *
 * Level 2 — per-scenario messages (onMin / onMax / onBetween / onExact):
 *   new ArrayCount(min: 2, max: 5, messages: [
 *       'onMin' => 'Please add at least :min addresses.',
 *       'onMax' => 'You can add at most :max addresses.',
 *   ])
 *
 * Level 3 — tokens inside any message string (safe, no array-to-string):
 *   :attribute  → human-readable field name  ("address ids")
 *   :min        → the min bound
 *   :max        → the max bound
 *   :count      → how many items were actually provided
 *
 * Priority:  per-scenario message  >  global message  >  built-in default
 *
 * ──────────────────────────────────────────────────────────────────
 * WHY ValidatorAwareRule:
 * Laravel's makeReplacements() → getDisplayableValue() calls Str::upper()
 * on the raw $value — which is an array — causing "Array to string conversion".
 * Pushing messages directly to errors()->add() bypasses that entire pipeline.
 * ──────────────────────────────────────────────────────────────────
 */

class ArrayCount implements ValidationRule, ValidatorAwareRule
{
    private Validator $validator;

    /**
     * @param int|null    $min      Minimum number of items (inclusive)
     * @param int|null    $max      Maximum number of items (inclusive)
     * @param string|null $message  Single custom message for ALL failure types
     * @param array<string,string> $messages  Per-scenario messages:
     *                                         'onMin', 'onMax', 'onBetween', 'onExact', 'onNotArray'
     */
    public function __construct(
        private readonly ?int    $min      = null,
        private readonly ?int    $max      = null,
        private readonly ?string $message  = null,
        private readonly array   $messages = [],
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

    /** Called automatically by Laravel — injects Validator instance. */
    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;
        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $label = $this->resolveLabel($attribute);
        $count = is_array($value) ? count($value) : null;

        // Build the token context for message interpolation
        $tokens = [
            ':attribute' => $label,
            ':min'       => (string) ($this->min ?? ''),
            ':max'       => (string) ($this->max ?? ''),
            ':count'     => (string) ($count ?? ''),
        ];

        if (! is_array($value)) {
            $this->addError($attribute, $this->resolve('onNotArray', "The {$label} must be an array.", $tokens));
            return;
        }

        // Exactly N
        if ($this->min !== null && $this->max !== null && $this->min === $this->max) {
            if ($count !== $this->min) {
                $noun    = Str::plural('item', $this->min);
                $default = "The {$label} must contain exactly {$this->min} {$noun}.";
                $this->addError($attribute, $this->resolve('onExact', $default, $tokens));
            }
            return;
        }

        // Between min and max
        if ($this->min !== null && $this->max !== null) {
            if ($count < $this->min || $count > $this->max) {
                $default = "The {$label} must contain between {$this->min} and {$this->max} items.";
                $this->addError($attribute, $this->resolve('onBetween', $default, $tokens));
            }
            return;
        }

        // Only minimum
        if ($this->min !== null && $count < $this->min) {
            $noun    = Str::plural('item', $this->min);
            $default = "The {$label} must contain at least {$this->min} {$noun}.";
            $this->addError($attribute, $this->resolve('onMin', $default, $tokens));
            return;
        }

        // Only maximum
        if ($this->max !== null && $count > $this->max) {
            $noun    = Str::plural('item', $this->max);
            $default = "The {$label} must not exceed {$this->max} {$noun}.";
            $this->addError($attribute, $this->resolve('onMax', $default, $tokens));
        }
    }

    /**
     * Resolve the final message applying priority:
     *   per-scenario  >  global $message  >  built-in $default
     *
     * Then replace :attribute / :min / :max / :count tokens safely
     * (tokens are always strings — no array-to-string risk).
     */
    private function resolve(string $scenario, string $default, array $tokens): string
    {
        $raw = $this->messages[$scenario]   // Level 2 — per-scenario
            ?? $this->message               // Level 1 — global custom
            ?? $default;                    // Level 0 — built-in

        return str_replace(array_keys($tokens), array_values($tokens), $raw);
    }

    /**
     * Push the final message directly into the Validator's MessageBag,
     * bypassing makeReplacements() — no placeholder left for Laravel to touch.
     */
    private function addError(string $attribute, string $message): void
    {
        $this->validator->errors()->add($attribute, $message);
    }

    /**
     * "user.address_ids"  →  "address ids"
     * "address_ids"       →  "address ids"
     */
    private function resolveLabel(string $attribute): string
    {
        return str_replace('_', ' ', Str::afterLast($attribute, '.'));
    }
}