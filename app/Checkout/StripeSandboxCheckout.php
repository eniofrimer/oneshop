<?php

namespace App\Checkout;

use App\Models\PaymentAttempt;
use App\Payments\PaymentLedger;
use App\Payments\StripeSandboxConfiguration;
use App\Storefront\GuestCart;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Cart;

class StripeSandboxCheckout
{
    public const OWNED_KEY = 'oneshop.stripe_sandbox_checkouts';

    public function __construct(private CheckoutDraft $draft, private PaymentLedger $ledger, private StripeSandboxConfiguration $configuration) {}

    public function guard(): void
    {
        abort_unless(StripeSandboxConfiguration::available(), 404);
    }

    /** @param array<string, mixed> $data */
    public function address(Session $session, array $data): void
    {
        $this->guard();
        $this->draft->address($session, $data);
    }

    public function selectShipping(Session $session, string $identifier): void
    {
        $this->guard();
        $this->draft->selectShipping($session, $identifier);
    }

    public function review(Session $session): Cart
    {
        $this->guard();

        return $this->draft->review($session);
    }

    public function place(Session $session, string $quote): PaymentAttempt
    {
        $this->guard();
        $identity = $this->configuration->identity();
        $returnBaseUrl = config('app.url');
        if (! is_string($returnBaseUrl)) {
            throw new \LogicException('A configured application URL is required.');
        }
        $cartId = $session->get(GuestCart::SESSION_KEY);
        abort_unless(is_int($cartId), 404);
        $attempt = DB::transaction(function () use ($cartId, $session, $quote, $identity, $returnBaseUrl): PaymentAttempt {
            $cart = Cart::query()->whereNull('user_id')->whereNull('customer_id')->lockForUpdate()->findOrFail($cartId);
            $existing = PaymentAttempt::query()->whereHas('order', fn ($query) => $query->where('cart_id', $cart->id))->first();
            if ($existing) {
                if ($existing->provider !== $identity->provider || $existing->provider_account !== $identity->account || $existing->environment !== $identity->environment) {
                    throw ValidationException::withMessages(['payment' => 'Ova košarica već ima pokušaj naplate koji zahtijeva provjeru.']);
                }

                return $existing;
            }
            $order = $this->draft->placeOrder($cart, $session, $quote, 'stripe-test');
            $attempt = $this->ledger->prepare($order, $identity, $returnBaseUrl);
            $cart->update(['completed_at' => now()]);

            return $attempt;
        });
        $owned = $session->get(self::OWNED_KEY, []);
        $owned = is_array($owned) ? $owned : [];
        $session->put(self::OWNED_KEY, array_slice(array_values(array_unique([...$owned, $attempt->id])), -20));

        return $attempt;
    }

    public function owned(Session $session, string $id): PaymentAttempt
    {
        $this->guard();
        $owned = $session->get(self::OWNED_KEY, []);
        abort_unless(is_array($owned) && in_array($id, $owned, true), 404);

        return PaymentAttempt::query()->with('order')->findOrFail($id);
    }
}
