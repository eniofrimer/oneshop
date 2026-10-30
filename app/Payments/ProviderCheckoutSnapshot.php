<?php

namespace App\Payments;

use Illuminate\Support\Str;

/** An authenticated, freshly retrieved session, not an event's old object copy. */
final readonly class ProviderCheckoutSnapshot
{
    public function __construct(
        public ProviderIdentity $identity,
        public string $objectId,
        public string $attemptId,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?CapturedPayment $capture = null,
        public ?string $checkoutUrl = null,
    ) {
        if (! preg_match('/\Acs_test_[A-Za-z0-9]{1,112}\z/D', $objectId) || ! Str::isUuid($attemptId)
            || ! in_array($status, ['open', 'complete', 'expired'], true) || $amountMinor < 0
            || ! preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            throw new \InvalidArgumentException('Invalid provider session.');
        }
        if ($checkoutUrl !== null) {
            $url = parse_url($checkoutUrl);
            if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== 'checkout.stripe.com'
                || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
                throw new \InvalidArgumentException('Invalid hosted checkout URL.');
            }
        }
    }
}
