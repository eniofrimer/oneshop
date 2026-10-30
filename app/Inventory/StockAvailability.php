<?php

namespace App\Inventory;

use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lunar\Models\ProductVariant;

class StockAvailability
{
    private static function heldSubquery(): QueryBuilder
    {
        return DB::table((new StockReservation)->getTable())->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('product_variant_id', (new ProductVariant)->getQualifiedKeyName())
            ->where('state', StockReservation::HELD);
    }

    /** @param Builder<ProductVariant> $query
     * @return Builder<ProductVariant>
     */
    public static function withHeld(Builder $query): Builder
    {
        return $query->addSelect((new ProductVariant)->qualifyColumn('*'))
            ->selectSub(self::heldSubquery(), 'oneshop_held_quantity');
    }

    /** @template TModel of Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function whereAvailable(Builder $query): Builder
    {
        if (! $query->getModel() instanceof ProductVariant) {
            throw new \LogicException('Availability queries require product variants.');
        }

        return $query->where(fn (Builder $stock) => $stock->where('purchasable', 'always')
            ->orWhere(fn (Builder $tracked) => $tracked->where('purchasable', 'in_stock')
                ->where((new ProductVariant)->qualifyColumn('stock'), '>', self::heldSubquery())));
    }

    public static function held(int $variantId): int
    {
        return (int) StockReservation::query()->where('product_variant_id', $variantId)
            ->where('state', StockReservation::HELD)->sum('quantity');
    }

    public static function available(ProductVariant $variant): int
    {
        $held = $variant->getAttribute('oneshop_held_quantity');
        $held = $held === null ? self::held($variant->id) : (int) $held;

        return max(0, $variant->stock - $held);
    }

    public static function canSupply(ProductVariant $variant, int $quantity = 1): bool
    {
        return $quantity > 0 && ($variant->purchasable === 'always'
            || ($variant->purchasable === 'in_stock' && self::available($variant) >= $quantity));
    }

    public static function requireQuantity(ProductVariant $variant, int $quantity, string $field = 'cart'): void
    {
        if (! self::canSupply($variant, $quantity)) {
            throw ValidationException::withMessages([$field => 'Tražena količina više nije raspoloživa. Provjerite košaricu.']);
        }
    }
}
