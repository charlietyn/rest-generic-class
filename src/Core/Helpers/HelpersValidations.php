<?php

namespace Ronu\RestGenericClass\Core\Helpers;

class HelpersValidations
{
    public static function validateUniqueValueInUpdateArray($attribute, $value, $fail,$request,$id): void
    {
        $dataAttributes = explode('.', $attribute);
        $lengthAttributes = count($dataAttributes);
        $table = $lengthAttributes > 0 ? $dataAttributes[0] : 0;
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