<?php

namespace Tests\Support;

/** Ephemeral fixtures, never issued by Stripe or used with a real HTTP client. */
final class StripeTestCredentials
{
    public static function secretKey(string $mode = 'test'): string
    {
        return 'sk_'.$mode.'_'.bin2hex(random_bytes(24));
    }

    public static function webhookSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(24));
    }
}
