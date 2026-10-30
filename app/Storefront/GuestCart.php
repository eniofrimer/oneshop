<?php

namespace App\Storefront;

use App\Inventory\StockAvailability;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Cart;
use Lunar\Models\CartLine;
use Lunar\Models\ProductVariant;

class GuestCart
{
    public const SESSION_KEY = 'oneshop.guest_cart';

    public function __construct(private StoreCatalog $catalog) {}

    public function itemCount(Session $session): int
    {
        return (int) ($this->current($session)?->lines()->sum('quantity') ?? 0);
    }

    public function current(Session $session, bool $create = false): ?Cart
    {
        $id = $session->get(self::SESSION_KEY);
        // IDs come only from the server-side session. Never accept a posted cart ID.
        $cart = is_int($id) ? Cart::query()->whereNull('user_id')->whereNull('customer_id')->whereNull('completed_at')
            ->whereNull('merged_id')->find($id) : null;
        if ($cart || ! $create) {
            return $cart;
        }
        $cart = Cart::query()->create(['currency_id' => $this->catalog->currency()->id, 'channel_id' => $this->catalog->channel()->id]);
        $session->put(self::SESSION_KEY, $cart->id);

        return $cart;
    }

    public function add(Session $session, int $variantId, int $quantity): void
    {
        $this->quantity($quantity);
        $cart = $this->current($session, true);
        DB::transaction(function () use ($cart, $variantId, $quantity): void {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $this->ensureEditable($cart);
            $variant = $this->catalog->purchasable($variantId);
            $existing = CartLine::query()->where('cart_id', $cart->id)->whereMorphedTo('purchasable', $variant)->first();
            $this->quantity(($existing ? $existing->quantity : 0) + $quantity);
            StockAvailability::requireQuantity($variant, ($existing ? $existing->quantity : 0) + $quantity);
            if (! $existing && $cart->lines()->count() >= 50) {
                throw ValidationException::withMessages(['cart' => 'Košarica podržava najviše 50 različitih artikala.']);
            }
            $cart->add($variant, $quantity);
        });
    }

    public function update(Session $session, int $lineId, int $quantity): void
    {
        $this->quantity($quantity);
        $cart = $this->current($session);
        abort_unless($cart !== null, 404);
        DB::transaction(function () use ($cart, $lineId, $quantity): void {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $this->ensureEditable($cart);
            $line = CartLine::query()->where('cart_id', $cart->id)->findOrFail($lineId);
            $variant = $this->catalog->purchasable($line->purchasable_id);
            StockAvailability::requireQuantity($variant, $quantity);
            $cart->updateLine($lineId, $quantity);
        });
    }

    public function remove(Session $session, int $lineId): void
    {
        $cart = $this->current($session);
        abort_unless($cart !== null, 404);
        DB::transaction(function () use ($cart, $lineId): void {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $this->ensureEditable($cart);
            $cart->lines()->findOrFail($lineId)->delete();
        });
    }

    public function ensureEditable(Cart $cart): void
    {
        if ($cart->completed_at || $cart->hasCompletedOrders()) {
            throw ValidationException::withMessages(['cart' => 'Ova košarica već ima predanu narudžbu. Započnite novu kupnju.']);
        }
    }

    public function validateLines(Cart $cart): void
    {
        if ($cart->lines->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Košarica je prazna.']);
        }
        foreach ($cart->lines as $line) {
            if (! $line instanceof CartLine || $line->purchasable_type !== (new ProductVariant)->getMorphClass()) {
                throw ValidationException::withMessages(['cart' => 'Nepodržana stavka košarice.']);
            }
            $variant = $this->catalog->purchasable($line->purchasable_id);
            $this->quantity($line->quantity);
            StockAvailability::requireQuantity($variant, $line->quantity);
        }
    }

    private function quantity(int $quantity): void
    {
        Validator::make(['quantity' => $quantity], ['quantity' => ['required', 'integer', 'min:1', 'max:99']])->validate();
    }
}
