<?php

namespace App\Inventory;

use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Lunar\Models\ProductVariant;

class StockReservations
{
    /** @return array<int, int> */
    public static function quantities(Order $order): array
    {
        $quantities = [];
        foreach ($order->productLines()->get() as $line) {
            if (! $line instanceof OrderLine || ($line->meta['oneshop_track_stock'] ?? $line->meta['oneshop_demo_track_stock'] ?? false) !== true) {
                continue;
            }
            if ($line->purchasable_type !== (new ProductVariant)->getMorphClass() || $line->quantity < 1) {
                throw new \LogicException('Unsupported tracked order line.');
            }
            $id = (int) $line->purchasable_id;
            $quantities[$id] = ($quantities[$id] ?? 0) + $line->quantity;
        }
        ksort($quantities, SORT_NUMERIC);

        return $quantities;
    }

    /** @param array<int, int> $quantities */
    public function reserve(string $key, Order $order, array $quantities): void
    {
        $this->validate($key, $quantities);
        DB::transaction(function () use ($key, $order, $quantities): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $expected = self::quantities($order);
            $requested = $quantities;
            ksort($requested, SORT_NUMERIC);
            if ($expected !== $requested) {
                throw new \LogicException('Reservation contents do not match the order snapshot.');
            }
            $variants = $this->variants($quantities);
            $existing = $this->rows($key);
            if ($existing->isNotEmpty()) {
                $this->match($existing, $order, $quantities);
                $this->requireState($existing, StockReservation::HELD);

                return;
            }
            if (StockReservation::query()->where('order_id', $order->id)->where('reservation_key', '!=', $key)
                ->whereIn('state', [StockReservation::HELD, StockReservation::CONSUMED])->exists()) {
                throw ValidationException::withMessages(['cart' => 'Narudžba već ima aktivnu ili potrošenu rezervaciju.']);
            }
            foreach ($quantities as $id => $quantity) {
                $variant = $variants->get($id);
                if (! $variant || $variant->trashed() || $variant->purchasable !== 'in_stock') {
                    throw ValidationException::withMessages(['cart' => 'Artikl više nije dostupan za rezervaciju.']);
                }
                StockAvailability::requireQuantity($variant, $quantity);
            }
            foreach ($quantities as $id => $quantity) {
                (new StockReservation)->forceFill(['reservation_key' => $key, 'order_id' => $order->id,
                    'product_variant_id' => $id, 'quantity' => $quantity, 'state' => StockReservation::HELD])->save();
            }
            if ($quantities !== []) {
                $this->audit($order, $key, 'inventory-reserved');
            }
        });
    }

    /** @param array<int, int> $quantities */
    public function consume(string $key, Order $order, array $quantities): void
    {
        $this->validate($key, $quantities);
        DB::transaction(function () use ($key, $order, $quantities): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $variants = $this->variants($quantities);
            $rows = $this->rows($key);
            $this->match($rows, $order, $quantities);
            if ($quantities === [] || $rows->every(fn (StockReservation $row): bool => $row->state === StockReservation::CONSUMED)) {
                return;
            }
            $this->requireState($rows, StockReservation::HELD);
            foreach ($quantities as $id => $quantity) {
                $variant = $variants->get($id);
                // Honour every outstanding hold, including other orders.
                if (! $variant || $variant->stock < StockAvailability::held($id)) {
                    throw ValidationException::withMessages(['payment' => 'Rezervirana zaliha zahtijeva provjeru. Naplata nije potvrđena.']);
                }
            }
            foreach ($quantities as $id => $quantity) {
                $variant = $variants->get($id);
                $variant->update(['stock' => $variant->stock - $quantity]);
            }
            foreach ($rows as $row) {
                $row->forceFill(['state' => StockReservation::CONSUMED, 'consumed_at' => now()])->save();
            }
            $this->audit($order, $key, 'inventory-consumed');
        });
    }

    /** @param array<int, int> $quantities */
    public function release(string $key, Order $order, array $quantities, string $reason): void
    {
        $this->validate($key, $quantities);
        if (! in_array($reason, ['demo-cancelled', 'demo-expired', 'provider-expired'], true)) {
            throw new \LogicException('A confirmed release reason is required.');
        }
        DB::transaction(function () use ($key, $order, $quantities, $reason): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->variants($quantities);
            $rows = $this->rows($key);
            $this->match($rows, $order, $quantities);
            if ($quantities === [] || $rows->every(fn (StockReservation $row): bool => $row->state === StockReservation::RELEASED)) {
                return;
            }
            $this->requireState($rows, StockReservation::HELD);
            foreach ($rows as $row) {
                $row->forceFill(['state' => StockReservation::RELEASED, 'released_at' => now(), 'release_reason' => $reason])->save();
            }
            $this->audit($order, $key, 'inventory-released');
        });
    }

    /** Existing pre-migration simulators never consume another order's hold. */
    public function consumeLegacy(Order $order): void
    {
        $quantities = self::quantities($order);
        DB::transaction(function () use ($order, $quantities): void {
            Order::query()->lockForUpdate()->findOrFail($order->id);
            if (StockReservation::query()->where('order_id', $order->id)->exists()) {
                throw new \LogicException('A reserved order cannot use legacy stock consumption.');
            }
            $variants = $this->variants($quantities);
            foreach ($quantities as $id => $quantity) {
                $variant = $variants->get($id);
                if (! $variant || StockAvailability::available($variant) < $quantity) {
                    throw ValidationException::withMessages(['payment' => 'Na raspoloživoj zalihi više nema tražene količine. Testna naplata nije provedena.']);
                }
            }
            foreach ($quantities as $id => $quantity) {
                $variant = $variants->get($id);
                $variant->update(['stock' => $variant->stock - $quantity]);
            }
        });
    }

    /** @param array<array-key, mixed> $quantities */
    private function validate(string $key, array $quantities): void
    {
        if (! Str::isUuid($key) || count($quantities) > 50) {
            throw new \LogicException('Invalid reservation identity or size.');
        }
        foreach ($quantities as $id => $quantity) {
            if (! is_int($id) || $id < 1 || ! is_int($quantity) || $quantity < 1 || $quantity > 2147483647) {
                throw new \LogicException('Invalid reservation quantity.');
            }
        }
    }

    /** @param array<int, int> $quantities
     * @return Collection<int, ProductVariant>
     */
    private function variants(array $quantities): Collection
    {
        return ProductVariant::withTrashed()->whereIn('id', array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /** @return Collection<int, StockReservation> */
    private function rows(string $key): Collection
    {
        return StockReservation::query()->where('reservation_key', $key)->orderBy('product_variant_id')->lockForUpdate()->get();
    }

    /** @param Collection<int, StockReservation> $rows
     * @param  array<int, int>  $quantities
     */
    private function match(Collection $rows, Order $order, array $quantities): void
    {
        $stored = [];
        foreach ($rows as $row) {
            if ((int) $row->order_id !== $order->id) {
                throw new \LogicException('Reservation belongs to another order.');
            }
            $stored[(int) $row->product_variant_id] = $row->quantity;
        }
        ksort($stored, SORT_NUMERIC);
        ksort($quantities, SORT_NUMERIC);
        if ($stored !== $quantities) {
            throw new \LogicException('Reservation contents do not match the order snapshot.');
        }
    }

    /** @param Collection<int, StockReservation> $rows */
    private function requireState(Collection $rows, string $state): void
    {
        if (! $rows->every(fn (StockReservation $row): bool => $row->state === $state)) {
            throw new \LogicException('Reservation cannot make this transition.');
        }
    }

    private function audit(Order $order, string $key, string $event): void
    {
        activity()->performedOn($order)->event($event)->withProperties(['reservation_key' => $key])->log($event);
    }
}
