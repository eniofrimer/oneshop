<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\Order;

/** @property array<string, mixed> $creation_parameters Decrypted request parameters. */
class PaymentAttempt extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['creation_parameters', 'checkout_url'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'creation_parameters' => 'encrypted:array', 'checkout_url' => 'encrypted',
            'last_checked_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $attempt): void {
            if ($attempt->isDirty(['id', 'order_id', 'provider', 'provider_account', 'environment', 'amount_minor',
                'currency_code', 'idempotency_key', 'creation_parameters', 'created_at'])) {
                throw new \LogicException('Payment request snapshots are immutable.');
            }
            if ($attempt->isDirty('provider_object_id') && $attempt->getRawOriginal('provider_object_id') !== null) {
                throw new \LogicException('A payment attempt cannot be rebound.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Payment history cannot be deleted.'));
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
