<?php

namespace App\Payments;

final readonly class VerifiedProviderEvent
{
    public const TYPES = ['checkout.session.completed', 'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed', 'checkout.session.expired'];

    public function __construct(
        public ProviderIdentity $identity,
        public string $eventId,
        public string $objectId,
        public string $type,
        public string $bodySha256,
    ) {
        if (! preg_match('/\Aevt_[A-Za-z0-9]{1,120}\z/D', $eventId)
            || ! preg_match('/\Acs_test_[A-Za-z0-9]{1,112}\z/D', $objectId)
            || ! in_array($type, self::TYPES, true) || ! preg_match('/\A[a-f0-9]{64}\z/D', $bodySha256)) {
            throw new \InvalidArgumentException('Invalid verified event.');
        }
    }
}
