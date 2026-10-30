<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable facts for future consumers. No mail, invoice or entitlement is sent here. */
class PaymentOutbox extends Model
{
    protected $table = 'payment_outbox';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Outbox facts are immutable.'));
        static::deleting(fn () => throw new \LogicException('Outbox history cannot be deleted.'));
    }
}
