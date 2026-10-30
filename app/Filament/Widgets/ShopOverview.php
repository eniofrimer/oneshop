<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ShippingMethods\ShippingMethodResource;
use App\Inventory\StockAvailability;
use App\Models\ShippingMethod;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;
use Lunar\Models\Order;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;

class ShopOverview extends StatsOverviewWidget
{
    protected static ?bool $isLazyLoaded = false;

    protected static ?int $sort = -2;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Pregled trgovine';

    protected ?string $description = 'Trenutačno stanje kataloga i zaprimljenih narudžbi. Testne narudžbe označene su zasebno.';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Product::class) && Gate::allows('viewAny', Order::class) && Gate::allows('viewAny', ShippingMethod::class);
    }

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $published = Product::query()->where('status', 'published')->count();
        $drafts = Product::query()->where('status', 'draft')->count();
        $variants = ProductVariant::query()->whereHas('product', fn ($query) => $query->whereNull('deleted_at'));
        $outOfStock = (clone $variants)->where('purchasable', 'in_stock')
            ->whereNot(fn ($query) => StockAvailability::whereAvailable($query))->count();
        $orders = Order::query()->whereNotNull('placed_at');
        $demo = (clone $orders)->where('meta->oneshop_mode', 'demo')->count();

        return [
            Stat::make('Proizvodi u katalogu', Product::query()->count())->description("$published objavljeno · $drafts u nacrtu")->url(ProductResource::getUrl()),
            Stat::make('Aktivni SKU-ovi', $variants->count())->description("Praćenih SKU-ova bez raspoložive zalihe: $outOfStock")->url(ProductResource::getUrl()),
            Stat::make('Zaprimljene narudžbe', $orders->count())->description("Testnih narudžbi: $demo")->url(OrderResource::getUrl()),
            Stat::make('Aktivni načini dostave', ShippingMethod::query()->where('enabled', true)->count())->description('Ručno postavljene tarife')->url(ShippingMethodResource::getUrl()),
        ];
    }
}
