<?php

namespace App\Orders;

use Lunar\Models\Transaction;

class PaymentLabels
{
    public static function driver(string $driver): string
    {
        return $driver === 'oneshop-demo' ? 'Testna simulacija — bez novca' : $driver;
    }

    public static function type(string $type): string
    {
        return match ($type) {
            'capture' => 'Naplata', 'refund' => 'Povrat', 'intent' => 'Namjera plaćanja', default => $type,
        };
    }

    public static function status(Transaction $payment): string
    {
        return $payment->driver === 'oneshop-demo' ? match ($payment->status) {
            'paid' => 'Testno potvrđeno', 'declined' => 'Testno odbijeno', default => $payment->status,
        } : $payment->status;
    }
}
