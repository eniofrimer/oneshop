<?php

namespace App\Payments;

/** The first provider milestone deliberately accepts Stripe test accounts only. */
final readonly class ProviderIdentity
{
    public function __construct(public string $account, public string $provider = 'stripe', public string $environment = 'test')
    {
        if ($provider !== 'stripe' || $environment !== 'test' || ! preg_match('/\Aacct_[A-Za-z0-9]{1,80}\z/D', $account)) {
            throw new \InvalidArgumentException('Unsupported payment provider scope.');
        }
    }
}
