<?php

namespace Ronu\RestGenericClass\Core\Helpers;

use Ronu\RestGenericClass\Core\Validation\UpdateArrayUniqueValidator;

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
        $message = UpdateArrayUniqueValidator::validate($attribute, $value, $request, $id, $dbconection);

        if ($message !== null) {
            $fail($message);
        }
    }
}
