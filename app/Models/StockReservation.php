<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    public const HELD = 'held';

    public const CONSUMED = 'consumed';

    public const RELEASED = 'released';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'consumed_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $reservation): void {
            if ($reservation->isDirty(['reservation_key', 'order_id', 'product_variant_id', 'quantity', 'created_at'])) {
                throw new \LogicException('Reservation contents are immutable.');
            }
        });
    }
}
