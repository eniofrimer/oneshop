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
        return match (self::modeKey($order)) {
            'demo' => 'Testna narudžba',
            'stripe-test' => 'Stripe sandbox',
            default => 'Standard',
        };
    }

    public static function isTest(Order $order): bool
    {
        return in_array(self::modeKey($order), ['demo', 'stripe-test'], true);
    }

    public static function description(Order $order): string
    {
        return self::isTest($order) ? self::mode($order).' — bez stvarne naplate i dostave' : 'Standard';
    }

    private static function modeKey(Order $order): ?string
    {
        $meta = $order->getAttribute('meta');
        $mode = (is_array($meta) || $meta instanceof ArrayAccess) ? ($meta['oneshop_mode'] ?? null) : null;

        return is_string($mode) ? $mode : null;
    }

    public static function color(Order $order): string
    {
        return match ($order->status) {
            'payment-received' => 'success',
            'payment-review' => 'danger',
            'awaiting-payment', 'payment-pending' => 'warning',
            default => 'gray',
        };
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
        if (self::modeKey($order) === 'stripe-test') {
            return match ($order->status) {
                'awaiting-payment', 'payment-pending' => 'Čeka se Stripe testna potvrda',
                'payment-received' => 'Stripe testna uplata potvrđena',
                'payment-expired' => 'Stripe testna naplata istekla',
                'payment-review' => 'Stripe testna uplata zahtijeva provjeru',
                default => $order->status,
            };
        }
        $label = config('lunar.orders.statuses.'.$order->status.'.label');

        return is_string($label) ? $label : $order->status;
    }
}
