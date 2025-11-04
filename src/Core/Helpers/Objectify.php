<?php


namespace Ronu\RestGenericClass\Core\Helpers;


class Objectify
{
    public function json_mapper($value, $recursive = true) {
        if (!empty($value) && is_string($value) &&
            $decoded = json_decode($value, true)) {
            return $decoded;
        } elseif (is_array($value) && $recursive) {
            return array_map('objectify::json_mapper', $value);
        } else {
            return $value;
        }
    }

    // currying, anyone?
    public function json_mapper_norecurse($value) {
        return objectify::json_mapper($value, false);
    }

    public function json_to_array($array, $recursive = true)
    {
        # if $array is not an array, let's make it array with one value of
        # former $array.
        if (!is_array($array)) {
            $array = array($array);
        }

        return array_map(
            $recursive ? 'Ronu\RestGenericClass\Core\Helpers\Objectify'
                : 'Ronu\RestGenericClass\Core\Helpers\Objectify', $array);
    }
}
