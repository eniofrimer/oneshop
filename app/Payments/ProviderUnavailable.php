<?php

namespace App\Payments;

/** Safe to persist/display: never wraps an SDK payload, key or provider URL. */
final class ProviderUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'provider-unavailable')
    {
        parent::__construct($reason);
    }
}
