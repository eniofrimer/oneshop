<?php

namespace App\Payments;

use App\Models\PaymentEvent;
use Illuminate\Support\Str;

class PaymentEventInbox
{
    public function record(VerifiedProviderEvent $event): PaymentEvent
    {
        $record = PaymentEvent::unguarded(fn (): PaymentEvent => PaymentEvent::query()->firstOrCreate(
            ['provider' => $event->identity->provider, 'provider_account' => $event->identity->account,
                'environment' => $event->identity->environment, 'provider_event_id' => $event->eventId],
            ['id' => (string) Str::uuid(), 'provider_object_id' => $event->objectId,
                'event_type' => $event->type, 'body_sha256' => $event->bodySha256, 'status' => 'received'],
        ));
        if ($record->provider_object_id !== $event->objectId || $record->event_type !== $event->type
            || ! hash_equals($record->body_sha256, $event->bodySha256)) {
            throw new \DomainException('Conflicting webhook delivery.');
        }

        return $record;
    }
}
