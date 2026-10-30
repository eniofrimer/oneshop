<?php

namespace App\Orders;

use Lunar\Models\Transaction;

class PaymentLabels
{
    public static function driver(string $driver): string
    {
        return match ($driver) {
            'oneshop-demo' => 'Testna simulacija — bez novca',
            'oneshop-stripe-test' => 'Stripe sandbox — bez stvarnog novca',
            default => $driver,
        };
    }

    public static function type(string $type): string
    {
        return match ($type) {
            'capture' => 'Naplata', 'refund' => 'Povrat', 'intent' => 'Namjera plaćanja', default => $type,
        };
    }

    public static function status(Transaction $payment): string
    {
        if ($payment->driver === 'oneshop-stripe-test' && $payment->status === 'succeeded') {
            return 'Testno potvrđeno (Stripe)';
        }

        return $payment->driver === 'oneshop-demo' ? match ($payment->status) {
            'paid' => 'Testno potvrđeno', 'declined' => 'Testno odbijeno', default => $payment->status,
        } : $payment->status;
    }
}
