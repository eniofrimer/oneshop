<?php

namespace App\Orders;

use ArrayAccess;
use Lunar\Models\Order;

class OrderLabels
{
    public static function isDemo(Order $order): bool
    {
        $meta = $order->getAttribute('meta');

        return (is_array($meta) || $meta instanceof ArrayAccess) && ($meta['oneshop_mode'] ?? null) === 'demo';
    }

    public static function mode(Order $order): string
    {
        return self::isDemo($order) ? 'Testna narudžba' : 'Standard';
    }

    public static function status(Order $order): string
    {
        if (self::isDemo($order)) {
            return match ($order->status) {
                'awaiting-payment' => 'Čeka se testna potvrda',
                'payment-received' => 'Testna uplata potvrđena',
                'demo-cancelled' => 'Testna narudžba otkazana',
                'demo-expired' => 'Testna narudžba istekla',
                default => $order->status,
            };
        }
        $label = config('lunar.orders.statuses.'.$order->status.'.label');

        return is_string($label) ? $label : $order->status;
    }
}
