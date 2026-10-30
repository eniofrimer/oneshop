<?php

namespace App\Checkout;

use App\Models\ShippingMethod;
use App\Shipping\ShippingOptions;
use App\Storefront\GuestCart;
use App\Storefront\StoreCatalog;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lunar\DataTypes\ShippingOption;
use Lunar\Models\Cart;
use Lunar\Models\CartAddress;
use Lunar\Models\CartLine;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;

/** Shared physical guest checkout preparation, independent of payment adapters. */
class CheckoutDraft
{
    public function __construct(private GuestCart $carts, private ShippingOptions $shipping) {}

    public function current(Session $session): Cart
    {
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

    /** Caller commits order, reservation and payment intent in one transaction. */
    public function placeOrder(Cart $cart, Session $session, string $quote, string $mode): Order
    {
        if (DB::transactionLevel() < 1 || ! in_array($mode, ['demo', 'stripe-test'], true)) {
            throw new \LogicException('Order preparation requires a transaction and an explicit checkout mode.');
        }
        $cart = Cart::query()->whereNull('user_id')->whereNull('customer_id')->whereNull('merged_id')->lockForUpdate()->findOrFail($cart->id);
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
            $meta['oneshop_track_stock'] = $variant->purchasable === 'in_stock';
            $line->update(['meta' => $meta]);
        }
        $cart->refresh();
        $meta = $cart->getAttribute('meta');
        $meta = $meta instanceof \ArrayObject ? $meta->getArrayCopy() : (is_array($meta) ? $meta : []);
        $cart->update(['meta' => [...$meta, 'oneshop_mode' => $mode]]);
        $order = $cart->createOrder();
        $amount = (int) $order->getRawOriginal('total');
        if (($mode === 'stripe-test' ? $amount < 50 : $amount < 0) || $amount > 2147483647 || $order->currency_code !== 'EUR') {
            throw ValidationException::withMessages(['cart' => 'Iznos prelazi podržani raspon testnog checkouta.']);
        }
        $order->update(['placed_at' => now()]);

        return $order;
    }

    public function validateCart(Cart $cart): void
    {
        $this->carts->validateLines($cart);
        foreach ($cart->lines as $line) {
            if (! $line instanceof CartLine || ! $line->purchasable instanceof ProductVariant || ! $line->purchasable->shippable) {
                throw ValidationException::withMessages(['cart' => 'Digitalni checkout još nije uključen.']);
            }
        }
        $cart->validateStock();
    }

    public function requireShipping(Cart $cart, bool $lock = false): void
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

    public function quote(Cart $cart): string
    {
        $shipping = $cart->getShippingOption();
        // Sign the actual reviewed contents, including descriptions and per-line
        // amounts. An unchanged grand total does not imply the same purchase.
        $lines = $cart->lines->sortBy('id')->map(function ($line): array {
            if (! $line instanceof CartLine) {
                throw new \LogicException('Expected a validated cart line.');
            }
            $variant = $line->purchasable;
            if (! $variant instanceof ProductVariant) {
                throw new \LogicException('Expected a validated product variant.');
            }

            return ['id' => $line->id, 'variant' => $variant->id, 'sku' => $variant->sku,
                'name' => $variant->getDescription(), 'option' => StoreCatalog::optionLabel($variant),
                'quantity' => $line->quantity, 'unit_price' => $line->unitPrice?->value,
                'subtotal' => $line->subTotal?->value, 'discount' => $line->discountTotal?->value,
                'tax' => $line->taxAmount?->value, 'total' => $line->total?->value];
        })->values()->all();
        $payload = ['version' => 2, 'cart' => $cart->id, 'currency' => $cart->currency_id, 'coupon' => $cart->coupon_code,
            'total' => $cart->total?->value, 'tax' => $cart->taxTotal?->value, 'lines' => $lines,
            'shipping' => $shipping ? ['identifier' => $shipping->identifier, 'name' => $shipping->name,
                'description' => $shipping->description, 'total' => $cart->shippingTotal?->value] : null,
            'addresses' => $cart->addresses()->orderBy('type')->get()->map->only([
                'type', 'country_id', 'first_name', 'last_name', 'line_one', 'line_two', 'city', 'postcode', 'contact_email', 'contact_phone', 'shipping_option',
            ])->all()];

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @return list<array<string, mixed>> */
    public function shippingQuotes(Cart $cart): array
    {
        $options = [];
        foreach ($this->shipping->forCart($cart) as $option) {
            $quoteCart = Cart::query()->findOrFail($cart->id);
            $quoteCart->shippingOptionOverride = $option;
            $quoteCart->recalculate();
            $total = $quoteCart->shippingTotal;
            if (! $total || ! is_int($total->value)) {
                throw new \LogicException('Expected a calculated shipping total.');
            }
            $options[] = ['identifier' => $option->identifier, 'name' => $option->name,
                'description' => $option->description, 'price' => $total->value];
        }
        $cart->recalculate();

        return $options;
    }

    public function lockCatalog(Cart $cart): void
    {
        $variantIds = CartLine::query()->where('cart_id', $cart->id)->pluck('purchasable_id');
        $productIds = ProductVariant::query()->whereIn('id', $variantIds)->pluck('product_id');
        Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
        ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();
        Price::query()->where('priceable_type', (new ProductVariant)->getMorphClass())->whereIn('priceable_id', $variantIds)->orderBy('id')->lockForUpdate()->get();
        // Value labels can be edited independently of their product. Hold them
        // through quote validation and Lunar's historical order-line creation.
        ProductOptionValue::query()->whereHas('variants', fn ($query) => $query->whereIn((new ProductVariant)->getQualifiedKeyName(), $variantIds))
            ->orderBy('id')->lockForUpdate()->get();
    }
}
