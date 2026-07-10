<?php

namespace Ronu\RestGenericClass\Core\Validation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpdateArrayUniqueValidator
{
    public static function validate(
        string $attribute,
        mixed $value,
        object|array $request,
        string $idColumn,
        ?string $dbconnection = null,
        string $requestItemsKey = 'users'
    ): ?string {
        $context = self::parseAttribute($attribute, $dbconnection);
        $ignoreValue = self::resolveIgnoreValue($request, $requestItemsKey, $context['index'], $idColumn);

        if ($ignoreValue === null || $ignoreValue === '') {
            return null;
        }

        $rule = Rule::unique($context['table'], $context['column'])
            ->ignore($ignoreValue, $idColumn);

        $validator = Validator::make(
            [$context['column'] => $value],
            [$context['column'] => $rule]
        );

        return $validator->fails()
            ? $validator->errors()->first($context['column'])
            : null;
    }

    /**
     * @return array{table: string, index: int|string, column: int|string}
     */
    public static function parseAttribute(string $attribute, ?string $dbconnection = null): array
    {
        $segments = explode('.', $attribute);
        $table = count($segments) > 0 ? $segments[0] : '0';

        return [
            'table' => $dbconnection ? $dbconnection . '.' . $table : $table,
            'index' => count($segments) > 1 ? $segments[1] : 0,
            'column' => count($segments) > 2 ? $segments[2] : 0,
        ];
    }

    private static function resolveIgnoreValue(
        object|array $request,
        string $requestItemsKey,
        int|string $index,
        string $idColumn
    ): mixed {
        $items = is_array($request)
            ? ($request[$requestItemsKey] ?? [])
            : ($request->{$requestItemsKey} ?? []);

        return is_array($items) ? ($items[$index][$idColumn] ?? null) : null;
    }
}
