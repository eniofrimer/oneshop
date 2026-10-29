<?php

namespace App\Storefront;

class FormValues
{
    public static function old(string $name, mixed $default = ''): string
    {
        $value = old($name, $default);

        return is_scalar($value) ? (string) $value : '';
    }
}
