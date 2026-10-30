<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            if ($event->isDirty(['id', 'provider', 'provider_account', 'environment', 'provider_event_id',
                'provider_object_id', 'event_type', 'body_sha256', 'created_at'])) {
                throw new \LogicException('Webhook delivery identity is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Webhook history cannot be deleted.'));
    }
}
