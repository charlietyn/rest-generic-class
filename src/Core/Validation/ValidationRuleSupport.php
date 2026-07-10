<?php

declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Validation;

use Illuminate\Validation\Validator;

class ValidationRuleSupport
{
    public static function normalizeValue(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        return empty($value) ? null : $value;
    }

    public static function extractIds(array $items, string $key = 'id'): array
    {
        if (empty($items)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(function ($item) use ($key) {
                if (is_int($item) || is_string($item)) {
                    return $item;
                }

                if (is_array($item) && array_key_exists($key, $item)) {
                    return $item[$key];
                }

                if (is_object($item) && property_exists($item, $key)) {
                    return $item->{$key};
                }

                return null;
            }, $items),
            static fn ($id): bool => $id !== null && $id !== ''
        )));
    }

    public static function extractIdsOrAddError(
        Validator $validator,
        string $attribute,
        mixed $value,
        string $inputKey,
        string $messageColumn
    ): ?array {
        $items = self::normalizeValue($value);

        if ($items === null) {
            return null;
        }

        $ids = self::extractIds($items, $inputKey);

        if (empty($ids)) {
            self::addNoIdsError($validator, $attribute, $messageColumn);
            return null;
        }

        return $ids;
    }

    public static function addNoIdsError(Validator $validator, string $attribute, string $column): void
    {
        $validator->errors()->add(
            $attribute,
            'Theres no IDs provided to validate.:' . $column
        );
    }

    public static function addMissingIdsError(
        Validator $validator,
        string $attribute,
        array $ids,
        array $conditions = []
    ): void {
        $validator->errors()->add(
            $attribute,
            'The following IDs do not exist: ' . implode(', ', $ids)
            . self::buildConditionsMessage($conditions)
        );
    }

    public static function buildConditionsMessage(array $conditions): string
    {
        if (empty($conditions)) {
            return '';
        }

        $parts = [];
        foreach ($conditions as $column => $value) {
            if (is_array($value)) {
                $parts[] = "{$column}=[" . implode(', ', $value) . ']';
            } else {
                $parts[] = "{$column}={$value}";
            }
        }

        return ' (conditions: ' . implode(', ', $parts) . ')';
    }
}
