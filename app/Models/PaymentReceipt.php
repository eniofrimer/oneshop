<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReceipt extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            if ($receipt->isDirty(['id', 'payment_attempt_id', 'provider', 'provider_account', 'environment',
                'capture_reference', 'payment_reference', 'amount_minor', 'currency_code', 'created_at'])) {
                throw new \LogicException('Confirmed financial facts are immutable.');
            }
            if ($receipt->isDirty('transaction_id') && $receipt->getRawOriginal('transaction_id') !== null) {
                throw new \LogicException('A capture cannot create another transaction.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Financial history cannot be deleted.'));
    }

    /** @return BelongsTo<PaymentAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }
}
