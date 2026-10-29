<?php

namespace App\Storefront;

enum StoreTheme: string
{
    case Everyday = 'everyday';
    case Atelier = 'atelier';
    case Studio = 'studio';
    case Market = 'market';

    public static function configured(): self
    {
        $value = config('oneshop.theme');

        return is_string($value) ? (self::tryFrom($value) ?? self::Everyday) : self::Everyday;
    }
}
