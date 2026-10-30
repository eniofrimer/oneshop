<?php

namespace App\Audit;

use App\Models\DigitalAsset;
use App\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Builder;
use Lunar\Models\Order;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Spatie\Activitylog\Models\Activity;

class AuditEvents
{
    public const LABELS = ['catalog-published' => 'Proizvod objavljen', 'catalog-withdrawn' => 'Proizvod vraćen u nacrt',
        'sku-archived' => 'SKU arhiviran', 'sku-restored' => 'SKU vraćen', 'inventory-reserved' => 'Zaliha rezervirana',
        'inventory-consumed' => 'Rezervirana zaliha potrošena', 'inventory-released' => 'Rezervacija oslobođena',
        'created' => 'Zapis dodan', 'updated' => 'Zapis ažuriran', 'deleted' => 'Zapis uklonjen'];

    /** @param Builder<Activity> $query
     * @return Builder<Activity>
     */
    public static function visible(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(fn (Builder $scope) => $scope
            ->where(fn (Builder $events) => $events->where($table.'.subject_type', (new Product)->getMorphClass())
                ->whereIn($table.'.event', ['catalog-published', 'catalog-withdrawn']))
            ->orWhere(fn (Builder $events) => $events->where($table.'.subject_type', (new ProductVariant)->getMorphClass())
                ->whereIn($table.'.event', ['sku-archived', 'sku-restored']))
            ->orWhere(fn (Builder $events) => $events->where($table.'.subject_type', (new Order)->getMorphClass())
                ->whereIn($table.'.event', ['inventory-reserved', 'inventory-consumed', 'inventory-released']))
            ->orWhere(fn (Builder $events) => $events->where($table.'.subject_type', (new ShippingMethod)->getMorphClass())
                ->where($table.'.log_name', 'shipping')->whereIn($table.'.event', ['created', 'updated', 'deleted']))
            ->orWhere(fn (Builder $events) => $events->where($table.'.subject_type', (new DigitalAsset)->getMorphClass())
                ->where($table.'.log_name', 'digital-assets')->whereIn($table.'.event', ['created', 'updated', 'deleted'])));
    }

    public static function subject(string $type): string
    {
        return match ($type) {
            (new Product)->getMorphClass() => 'Proizvod', (new ProductVariant)->getMorphClass() => 'SKU',
            (new Order)->getMorphClass() => 'Narudžba', (new ShippingMethod)->getMorphClass() => 'Dostava',
            (new DigitalAsset)->getMorphClass() => 'Digitalna datoteka', default => 'Zapis',
        };
    }
}
