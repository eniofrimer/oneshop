<?php

namespace App\Payments;

final class StripeSandboxConfiguration
{
    public const API_VERSION = '2026-09-30.endive';

    public static function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('payments.stripe_sandbox.enabled') === true;
    }

    public static function available(): bool
    {
        if (! self::enabled()) {
            return false;
        }
        try {
            $configuration = new self;
            $configuration->identity();
            $configuration->key();
            $configuration->webhookSecret();

            return true;
        } catch (\LogicException) {
            return false;
        }
    }

    public function identity(): ProviderIdentity
    {
        $this->guard();
        $account = config('payments.stripe_sandbox.account');
        if (! is_string($account)) {
            throw new \LogicException('Stripe sandbox account is not configured.');
        }

        try {
            return new ProviderIdentity($account);
        } catch (\InvalidArgumentException) {
            throw new \LogicException('A valid Stripe test account ID is required.');
        }
    }

    public function key(): string
    {
        $this->guard();
        $key = config('payments.stripe_sandbox.secret_key');
        if (! is_string($key) || ! preg_match('/\Ask_test_[A-Za-z0-9]{8,}\z/D', $key)) {
            throw new \LogicException('A Stripe test secret key is required.');
        }

        return $key;
    }

    public function webhookSecret(): string
    {
        $this->guard();
        $secret = config('payments.stripe_sandbox.webhook_secret');
        if (! is_string($secret) || ! preg_match('/\Awhsec_[A-Za-z0-9]{8,}\z/D', $secret)) {
            throw new \LogicException('A dedicated Stripe test webhook secret is required.');
        }

        return $secret;
    }

    private function guard(): void
    {
        if (! self::enabled()) {
            throw new \LogicException('Stripe sandbox is disabled.');
        }
    }
}
