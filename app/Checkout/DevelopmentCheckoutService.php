<?php

namespace App\Checkout;

use App\Inventory\StockReservations;
use App\Models\DevelopmentCheckout;
use App\Storefront\GuestCart;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Cart;
use Lunar\Models\Order;
use Lunar\Models\Transaction;

/**
 * Development simulator only. No PSP calls, real money or digital
 * entitlements. External settlement requires provider verification and reconciliation.
 */
class DevelopmentCheckoutService
{
    public const OWNED_KEY = 'oneshop.development_checkouts';

    public function __construct(private CheckoutDraft $draft, private StockReservations $reservations) {}

    public static function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('oneshop.demo_checkout') === true;
    }

    public function guard(): void
    {
        abort_unless(self::enabled(), 404);
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

    public function place(Session $session, string $quote): DevelopmentCheckout
    {
        $this->guard();
        $cartId = $session->get(GuestCart::SESSION_KEY);
        abort_unless(is_int($cartId), 404);
        $checkout = DB::transaction(function () use ($cartId, $session, $quote): DevelopmentCheckout {
            $cart = Cart::query()->whereNull('user_id')->whereNull('customer_id')->lockForUpdate()->findOrFail($cartId);
            $existing = DevelopmentCheckout::query()->where('cart_id', $cart->id)->first();
            if ($existing) {
                return $existing;
            }
            $order = $this->draft->placeOrder($cart, $session, $quote, 'demo');
            $amount = (int) $order->getRawOriginal('total');
            $checkout = new DevelopmentCheckout;
            $checkout->forceFill(['id' => (string) Str::uuid(), 'cart_id' => $cart->id, 'order_id' => $order->id,
                'amount_minor' => $amount, 'currency_code' => 'EUR', 'status' => 'pending', 'expires_at' => now()->addMinutes(30),
                'uses_stock_reservations' => true])->save();
            $this->reservations->reserve($checkout->id, $order, StockReservations::quantities($order));
            $cart->update(['completed_at' => now()]);

            return $checkout;
        });
        $owned = $session->get(self::OWNED_KEY, []);
        $owned = is_array($owned) ? $owned : [];
        $session->put(self::OWNED_KEY, array_slice(array_values(array_unique([...$owned, $checkout->id])), -20));

        return $checkout;
    }

    public function owned(Session $session, string $id): DevelopmentCheckout
    {
        $this->guard();
        $owned = $session->get(self::OWNED_KEY, []);
        abort_unless(is_array($owned) && in_array($id, $owned, true), 404);

        return DevelopmentCheckout::query()->with('order')->findOrFail($id);
    }

    public function simulate(Session $session, string $id, string $outcome): DevelopmentCheckout
    {
        $owned = $this->owned($session, $id);
        Validator::make(['outcome' => $outcome], ['outcome' => ['required', Rule::in(['paid', 'declined', 'cancelled'])]])->validate();

        return DB::transaction(function () use ($owned, $outcome): DevelopmentCheckout {
            $checkout = DevelopmentCheckout::query()->lockForUpdate()->findOrFail($owned->id);
            if (in_array($checkout->status, ['paid', 'cancelled', 'expired'], true)) {
                return $checkout;
            }
            $order = Order::query()->lockForUpdate()->findOrFail($checkout->order_id);
            if ((int) $order->getRawOriginal('total') !== $checkout->amount_minor || $order->currency_code !== $checkout->currency_code) {
                throw ValidationException::withMessages(['payment' => 'Uložen iznos narudžbe ne odgovara testnoj naplati.']);
            }
            if ($checkout->expires_at->isPast()) {
                $this->expire($checkout, $order);

                return $checkout;
            }
            if ($outcome === 'cancelled') {
                if ($checkout->uses_stock_reservations) {
                    $this->reservations->release($checkout->id, $order, StockReservations::quantities($order), 'demo-cancelled');
                }
                $checkout->forceFill(['status' => 'cancelled'])->save();
                $order->update(['status' => 'demo-cancelled']);

                return $checkout;
            }
            if ($outcome === 'declined' && $checkout->status === 'declined') {
                return $checkout;
            }
            if ($outcome === 'paid') {
                if ($checkout->uses_stock_reservations) {
                    $this->reservations->consume($checkout->id, $order, StockReservations::quantities($order));
                } else {
                    $this->reservations->consumeLegacy($order);
                }
            }
            Transaction::query()->create(['order_id' => $order->id, 'type' => 'capture', 'success' => $outcome === 'paid',
                'driver' => 'oneshop-demo', 'amount' => $checkout->amount_minor, 'reference' => 'demo:'.$checkout->id.':'.$outcome,
                'status' => $outcome, 'card_type' => null, 'last_four' => null, 'notes' => 'Razvojna simulacija. Novac nije naplaćen.', 'meta' => ['mode' => 'demo']]);
            $checkout->forceFill(['status' => $outcome, 'settled_at' => $outcome === 'paid' ? now() : null])->save();
            if ($outcome === 'paid') {
                $order->update(['status' => 'payment-received']);
            }

            return $checkout;
        });
    }

    public function expirePending(int $limit = 100): int
    {
        // This expiry is safe only for a simulator that cannot charge externally.
        // A future PSP timeout must keep its hold until reconciliation.
        $this->guard();
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Expiry batch must contain 1–1000 records.');
        }
        $ids = DevelopmentCheckout::query()->where('uses_stock_reservations', true)
            ->whereIn('status', ['pending', 'declined'])->where('expires_at', '<=', now())
            ->orderBy('expires_at')->orderBy('id')->limit($limit)->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            $expired += DB::transaction(function () use ($id): int {
                $checkout = DevelopmentCheckout::query()->lockForUpdate()->findOrFail($id);
                if (! in_array($checkout->status, ['pending', 'declined'], true) || $checkout->expires_at->isFuture()) {
                    return 0;
                }
                $order = Order::query()->lockForUpdate()->findOrFail($checkout->order_id);
                $this->expire($checkout, $order);

                return 1;
            });
        }

        return $expired;
    }

    private function expire(DevelopmentCheckout $checkout, Order $order): void
    {
        if ($checkout->uses_stock_reservations) {
            $this->reservations->release($checkout->id, $order, StockReservations::quantities($order), 'demo-expired');
        }
        $checkout->forceFill(['status' => 'expired'])->save();
        $order->update(['status' => 'demo-expired']);
    }
}
