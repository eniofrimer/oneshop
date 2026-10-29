<?php

namespace App\Checkout;

use App\Models\DevelopmentCheckout;
use App\Models\ShippingMethod;
use App\Shipping\ShippingOptions;
use App\Storefront\GuestCart;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lunar\DataTypes\ShippingOption;
use Lunar\Models\Cart;
use Lunar\Models\CartAddress;
use Lunar\Models\CartLine;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;

/**
 * Development simulator only. No PSP calls, real money, reservations or digital
 * entitlements. Production settlement requires the separately deferred review.
 */
class DevelopmentCheckoutService
{
    public const OWNED_KEY = 'oneshop.development_checkouts';

    public function __construct(private GuestCart $carts, private ShippingOptions $shipping) {}

    public static function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('oneshop.demo_checkout') === true;
    }

    public function guard(): void
    {
        abort_unless(self::enabled(), 404);
    }

    private function current(Session $session): Cart
    {
        $this->guard();
        $cart = $this->carts->current($session);
        if (! $cart) {
            throw ValidationException::withMessages(['cart' => 'Za završetak kupnje najprije dodajte proizvod u košaricu.']);
        }

        return $cart;
    }

    /** @param array<string, mixed> $data */
    public function address(Session $session, array $data): void
    {
        $cart = $this->current($session);
        $validated = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'contact_email' => ['required', 'email:rfc', 'max:200'], 'contact_phone' => ['required', 'string', 'max:40'],
            'line_one' => ['required', 'string', 'max:200'], 'line_two' => ['nullable', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:100'], 'postcode' => ['required', 'string', 'max:20'],
            'country_id' => ['required', 'integer', Rule::exists(Country::class, 'id')],
        ])->validate();
        DB::transaction(function () use ($cart, $validated): void {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $this->carts->ensureEditable($cart);
            $this->validateCart($cart);
            $cart->addAddress($validated, 'billing', refresh: false);
            $cart->addAddress($validated, 'shipping', refresh: false);
            $cart->refresh();
            $options = $this->shipping->forCart($cart);
            if ($options === []) {
                throw ValidationException::withMessages(['country_id' => 'Za ovu adresu trenutačno nema dostupne dostave.']);
            }
            // The review page clearly shows this initial choice and allows changes.
            $cart->setShippingOption($options[0]);
        });
        $session->forget('oneshop.checkout_quote');
    }

    public function selectShipping(Session $session, string $identifier): void
    {
        $cart = $this->current($session);
        DB::transaction(function () use ($cart, $identifier): void {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $this->carts->ensureEditable($cart);
            $this->validateCart($cart);
            $option = collect($this->shipping->forCart($cart))->first(fn (ShippingOption $option) => $option->identifier === $identifier);
            if (! $option) {
                throw ValidationException::withMessages(['shipping' => 'Odabrana dostava više nije dostupna za ovu adresu.']);
            }
            $cart->setShippingOption($option);
        });
        $session->forget('oneshop.checkout_quote');
    }

    public function review(Session $session): Cart
    {
        $cart = $this->current($session);
        $this->validateCart($cart);
        if (! $cart->billingAddress || ! $cart->shippingAddress) {
            throw ValidationException::withMessages(['address' => 'Najprije unesite adresu za račun i dostavu.']);
        }
        $this->requireShipping($cart);
        $cart->recalculate();
        $session->put('oneshop.checkout_quote', $this->quote($cart));

        return $cart;
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
            $this->carts->ensureEditable($cart);
            $this->lockCatalog($cart);
            $this->validateCart($cart);
            $this->requireShipping($cart, lock: true);
            $cart->recalculate();
            $expected = $session->get('oneshop.checkout_quote');
            if (! is_string($expected) || ! hash_equals($expected, $quote) || ! hash_equals($this->quote($cart), $quote)) {
                throw ValidationException::withMessages(['quote' => 'Košarica ili cijene su promijenjene. Ponovno pregledajte konačan iznos.']);
            }
            foreach (CartLine::query()->where('cart_id', $cart->id)->get() as $line) {
                $variant = $line->purchasable;
                if (! $variant instanceof ProductVariant) {
                    throw ValidationException::withMessages(['cart' => 'Artikl više nije dostupan.']);
                }
                $meta = $line->meta ?? [];
                $meta['oneshop_demo_track_stock'] = $variant->purchasable === 'in_stock';
                $line->update(['meta' => $meta]);
            }
            $cart->refresh();
            $meta = $cart->getAttribute('meta');
            $meta = $meta instanceof \ArrayObject ? $meta->getArrayCopy() : (is_array($meta) ? $meta : []);
            $cart->update(['meta' => [...$meta, 'oneshop_mode' => 'demo']]);
            $order = $cart->createOrder();
            $amount = (int) $order->getRawOriginal('total');
            if ($amount < 0 || $amount > 2147483647 || $order->currency_code !== 'EUR') {
                throw ValidationException::withMessages(['cart' => 'Iznos prelazi podržani raspon testnog checkouta.']);
            }
            $order->update(['placed_at' => now()]);
            $checkout = new DevelopmentCheckout;
            $checkout->forceFill(['id' => (string) Str::uuid(), 'cart_id' => $cart->id, 'order_id' => $order->id,
                'amount_minor' => $amount, 'currency_code' => 'EUR', 'status' => 'pending', 'expires_at' => now()->addMinutes(30)])->save();
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
                $checkout->forceFill(['status' => 'expired'])->save();
                $order->update(['status' => 'demo-expired']);

                return $checkout;
            }
            if ($outcome === 'cancelled') {
                $checkout->forceFill(['status' => 'cancelled'])->save();
                $order->update(['status' => 'demo-cancelled']);

                return $checkout;
            }
            if ($outcome === 'declined' && $checkout->status === 'declined') {
                return $checkout;
            }
            if ($outcome === 'paid') {
                $this->consumeStock($order);
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

    private function validateCart(Cart $cart): void
    {
        $this->carts->validateLines($cart);
        foreach ($cart->lines as $line) {
            if (! $line instanceof CartLine || ! $line->purchasable instanceof ProductVariant || ! $line->purchasable->shippable) {
                throw ValidationException::withMessages(['cart' => 'Digitalni checkout još nije uključen u razvojnu simulaciju.']);
            }
        }
        $cart->validateStock();
    }

    private function requireShipping(Cart $cart, bool $lock = false): void
    {
        $address = $cart->shippingAddress;
        $identifier = $address instanceof CartAddress ? $address->shipping_option : null;
        if ($lock && is_string($identifier) && str_starts_with($identifier, ShippingOptions::PREFIX)) {
            ShippingMethod::query()->where('code', substr($identifier, strlen(ShippingOptions::PREFIX)))->lockForUpdate()->get();
        }
        if (! is_string($identifier) || ! collect($this->shipping->forCart($cart))->contains(fn (ShippingOption $option) => $option->identifier === $identifier)) {
            throw ValidationException::withMessages(['shipping' => 'Dostava više nije dostupna. Ponovno unesite adresu ili odaberite drugu metodu.']);
        }
    }

    private function quote(Cart $cart): string
    {
        $payload = ['cart' => $cart->id, 'currency' => $cart->currency_id, 'total' => $cart->total?->value, 'tax' => $cart->taxTotal?->value,
            'fingerprint' => $cart->fingerprint(), 'addresses' => $cart->addresses()->orderBy('type')->get()->map->only([
                'type', 'country_id', 'first_name', 'last_name', 'line_one', 'line_two', 'city', 'postcode', 'contact_email', 'contact_phone', 'shipping_option',
            ])->all()];

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function lockCatalog(Cart $cart): void
    {
        $variantIds = CartLine::query()->where('cart_id', $cart->id)->pluck('purchasable_id');
        $productIds = ProductVariant::query()->whereIn('id', $variantIds)->pluck('product_id');
        Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
        ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();
        Price::query()->where('priceable_type', (new ProductVariant)->getMorphClass())->whereIn('priceable_id', $variantIds)->orderBy('id')->lockForUpdate()->get();
    }

    private function consumeStock(Order $order): void
    {
        $quantities = [];
        foreach (OrderLine::query()->where('order_id', $order->id)->where('type', 'physical')->get() as $line) {
            if (($line->meta['oneshop_demo_track_stock'] ?? false) === true) {
                $quantities[$line->purchasable_id] = ($quantities[$line->purchasable_id] ?? 0) + $line->quantity;
            }
        }
        $variants = ProductVariant::withTrashed()->whereIn('id', array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($quantities as $id => $quantity) {
            $variant = $variants->get($id);
            if (! $variant || $variant->stock < $quantity) {
                throw ValidationException::withMessages(['payment' => 'Na zalihi više nema tražene količine. Testna naplata nije provedena.']);
            }
        }
        foreach ($quantities as $id => $quantity) {
            $variant = $variants->get($id);
            $variant->update(['stock' => $variant->stock - $quantity]);
        }
    }
}
