<?php

namespace Ronu\RestGenericClass\Core\Helpers;
/**
 * Class HelpersValidations
 *
 * Provides validation helper methods.
 */
class HelpersValidations
{
    /**
     * Validates that a value is unique within an update array.
     *
     * @param string   $attribute    The attribute name being validated.
     * @param mixed    $value        The value of the attribute.
     * @param callable $fail         Callback function to trigger a validation failure.
     * @param object   $request      The request object containing user data.
     * @param string   $id           The identifier column name.
     * @param string|null $dbconection Optional database connection name.
     *
     * @return void
     */
    public static function validateUniqueValueInUpdateArray($attribute, $value, $fail, $request, $id, $dbconection = null): void
    {
        $dataAttributes = explode('.', $attribute);
        $lengthAttributes = count($dataAttributes);
        $table = $lengthAttributes > 0 ? $dataAttributes[0] : 0;
        $table = $dbconection ? $dbconection . '.' . $table : $table;
        $index = $lengthAttributes > 1 ? $dataAttributes[1] : 0;
        $userId = $request->users[$index][$id] ?? null;
        $attribute = $lengthAttributes > 2 ? $dataAttributes[2] : 0;
        if ($userId) {
            $rule = \Illuminate\Validation\Rule::unique($table, $attribute)->ignore($userId, $id);
            $validator = \Illuminate\Support\Facades\Validator::make([$attribute => $value], [$attribute => $rule]);
            if ($validator->fails()) {
                $fail($validator->errors()->first($attribute));
            }
        }
    }
}