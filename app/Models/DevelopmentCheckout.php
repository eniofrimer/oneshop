<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\Order;

/**
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $settled_at
 */
class DevelopmentCheckout extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'uses_stock_reservations' => 'boolean', 'expires_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $checkout): void {
            if ($checkout->isDirty(['id', 'cart_id', 'order_id', 'amount_minor', 'currency_code', 'expires_at', 'created_at', 'uses_stock_reservations'])) {
                throw new \LogicException('Checkout snapshots are immutable.');
            }
        });
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
