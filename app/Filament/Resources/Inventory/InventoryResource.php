<?php

namespace App\Filament\Resources\Inventory;

use App\Catalog\CatalogTranslations;
use App\Catalog\ProductSearch;
use App\Filament\Resources\Inventory\Pages\ListInventory;
use App\Filament\Resources\Products\ProductResource;
use App\Inventory\StockAvailability;
use App\Storefront\StoreCatalog;
use App\Support\ShopTime;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;

/** @extends resource<ProductVariant> */
class InventoryResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static ?string $slug = 'inventory';

    protected static ?string $modelLabel = 'zaliha';

    protected static ?string $pluralModelLabel = 'Zalihe';

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 40;

    protected static bool $isGloballySearchable = false;

    /** @return Builder<ProductVariant> */
    public static function getEloquentQuery(): Builder
    {
        Gate::authorize('viewAny', ProductVariant::class);

        return StockAvailability::withHeld(parent::getEloquentQuery())
            ->whereHas('product', fn (Builder $query) => $query->whereNull('deleted_at'))
            ->with(['product', 'values']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->description('Aktivni SKU-ovi svih proizvoda. Raspoloživo je zaliha umanjena za rezervacije; otvori proizvod za uređivanje.')
            ->searchPlaceholder('SKU ili naziv proizvoda')
            ->columns([
                TextColumn::make('sku')->label('SKU')->placeholder('Nije upisan SKU')->weight('medium')->wrap()->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => app(ProductSearch::class)->applyToVariants($query, $search)),
                TextColumn::make('product_name')->label('Proizvod / varijanta')->wrap()
                    ->state(function (ProductVariant $record): string {
                        $product = $record->getRelationValue('product');

                        return $product instanceof Product ? CatalogTranslations::formData($product)[config('oneshop.primary_locale')]['name'] : 'Nedostupan proizvod';
                    })->description(fn (ProductVariant $record): string => ($label = StoreCatalog::optionLabel($record)) !== '' ? $label : 'Standard'),
                TextColumn::make('stock')->label('Zaliha')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((int) $state, 0, ',', '.')),
                TextColumn::make('oneshop_held_quantity')->label('Rezervirano')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((int) $state, 0, ',', '.')),
                TextColumn::make('available')->label('Raspoloživo')->alignEnd()->badge()
                    ->state(fn (ProductVariant $record): string => match ($record->purchasable) {
                        'always' => 'Bez ograničenja', 'in_stock' => number_format(StockAvailability::available($record), 0, ',', '.'), default => 'Za provjeru',
                    })->color(fn (ProductVariant $record): string => match ($record->purchasable) {
                        'always' => 'info', 'in_stock' => StockAvailability::available($record) > 0 ? 'success' : 'danger', default => 'warning',
                    }),
                TextColumn::make('product.status')->label('Status proizvoda')->badge()->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'draft' => 'Nacrt', 'published' => 'Objavljeno', default => 'Za provjeru'
                    }),
                TextColumn::make('updated_at')->label('Promijenjeno')->dateTime('d.m.Y. H:i', timezone: ShopTime::zone())->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                SelectFilter::make('availability')->label('Raspoloživost')->options(['available' => 'Raspoloživo', 'unavailable' => 'Bez raspoložive zalihe'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'available' => StockAvailability::whereAvailable($query),
                        'unavailable' => $query->whereNot(fn (Builder $stock) => StockAvailability::whereAvailable($stock)), default => $query,
                    }),
                SelectFilter::make('purchasable')->label('Praćenje zalihe')->options(['in_stock' => 'Samo raspoloživa zaliha', 'always' => 'Bez ograničenja zalihom']),
                SelectFilter::make('product_status')->label('Status proizvoda')->options(['draft' => 'Nacrt', 'published' => 'Objavljeno'])
                    ->query(fn (Builder $query, array $data): Builder => in_array($data['value'] ?? null, ['draft', 'published'], true)
                        ? $query->whereHas('product', fn (Builder $products) => $products->where('status', $data['value'])) : $query),
                SelectFilter::make('shippable')->label('Način isporuke')->options(['1' => 'Fizička dostava', '0' => 'Bez fizičke dostave']),
            ])->recordUrl(function (ProductVariant $record): ?string {
                $product = $record->getRelationValue('product');

                return $product instanceof Product && ! $product->trashed() && Gate::allows('view', $product)
                    ? ProductResource::getUrl('edit', ['record' => $product]) : null;
            })->defaultSort('sku')->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(25)
            ->emptyStateHeading('Nema SKU-ova za ovaj odabir')
            ->emptyStateDescription('Promijeni filtre ili dodaj SKU u proizvodu.')->emptyStateIcon('heroicon-o-archive-box');
    }

    public static function getPages(): array
    {
        return ['index' => ListInventory::route('/')];
    }
}
