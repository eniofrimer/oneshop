<?php

namespace App\Payments;

/** Created only from an authenticated provider API response; never from a form. */
final readonly class CapturedPayment
{
    public function __construct(
        public string $captureReference,
        public string $paymentReference,
        public int $amountMinor,
        public string $currency,
        public bool $reversed = false,
        public bool $inconsistent = false,
    ) {
        if (! preg_match('/\Ach_[A-Za-z0-9]{1,120}\z/D', $captureReference)
            || ! preg_match('/\Api_[A-Za-z0-9]{1,120}\z/D', $paymentReference)
            || $amountMinor < 1 || ! preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            throw new \InvalidArgumentException('Invalid confirmed capture.');
        }
    }
}
