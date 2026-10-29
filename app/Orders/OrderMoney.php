<?php

namespace App\Orders;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Lunar\Models\Currency;
use Lunar\Models\Order;

class OrderMoney
{
    public static function format(Model $record, string $attribute): string
    {
        $order = $record instanceof Order ? $record : $record->getRelationValue('order');
        if (! $order instanceof Order) {
            throw new \LogicException('An order snapshot is required to display an amount.');
        }
        $minor = $record->getRawOriginal($attribute);
        if (! is_int($minor) && (! is_string($minor) || ! preg_match('/^-?\d+$/D', $minor))) {
            return '—';
        }
        $currency = $order->getRelationValue('currency');
        if (! $currency instanceof Currency || $currency->code !== $order->currency_code || $currency->decimal_places < 0 || $currency->decimal_places > 8) {
            // Never reinterpret unknown order currencies using today's shop default.
            return $minor.' '.$order->currency_code.' (najmanje jedinice)';
        }

        return str_replace('.', ',', (string) BigDecimal::ofUnscaledValue($minor, $currency->decimal_places)).' '.$order->currency_code;
    }
}
