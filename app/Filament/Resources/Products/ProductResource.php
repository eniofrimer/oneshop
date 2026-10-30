<?php

namespace App\Filament\Resources\Products;

use App\Catalog\ProductEditor;
use App\Catalog\ProductImages;
use App\Catalog\ProductPublication;
use App\Catalog\ProductSearch;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\ImagesRelationManager;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Filament\Support\ResolvesNumericRecords;
use App\Storefront\StoreCatalog;
use App\Support\ShopTime;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Lunar\Models\Collection;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductType;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** @extends resource<Product> */
class ProductResource extends Resource
{
    use ResolvesNumericRecords;

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['attribute_data->name->value->'.config('oneshop.primary_locale'), 'variants.sku'];
    }

    /** @return \Illuminate\Support\Collection<int, GlobalSearchResult> */
    public static function getGlobalSearchResults(string $search): \Illuminate\Support\Collection
    {
        Gate::authorize('viewAny', Product::class);
        $search = trim($search);
        if (strlen($search) > 240 || ! mb_check_encoding($search, 'UTF-8') || mb_strlen($search) < 2 || mb_strlen($search) > 60) {
            return collect();
        }

        return app(ProductSearch::class)->apply(static::getEloquentQuery(), $search)
            ->with('productType')->orderByDesc('id')->limit(20)->get()
            ->map(function (Product $record): GlobalSearchResult {
                $type = $record->getRelationValue('productType');
                $typeName = $type instanceof Model ? $type->getAttribute('name') : null;

                return new GlobalSearchResult(
                    title: (string) $record->translateAttribute('name', config('oneshop.primary_locale')),
                    url: static::getUrl('edit', ['record' => $record]),
                    details: ['Status' => match ($record->status) {
                        'draft' => 'Nacrt', 'published' => 'Objavljeno', default => 'Za provjeru',
                    }, 'Vrsta' => is_string($typeName) ? $typeName : '—'],
                );
            });
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 10;

    protected static ?string $model = Product::class;

    protected static ?string $modelLabel = 'proizvod';

    protected static ?string $pluralModelLabel = 'Proizvodi';

    public static function form(Schema $schema): Schema
    {
        $tabs = [];
        foreach (ProductEditor::locales() as $locale) {
            $tabs[] = Tab::make(strtoupper($locale))->schema([
                TextInput::make("translations.$locale.name")
                    ->label('Naziv')->required($locale === config('oneshop.primary_locale'))->maxLength(255),
                Textarea::make("translations.$locale.description")
                    ->label('Opis')->rows(6)->maxLength(20000),
            ]);
        }

        return $schema->components([
            Select::make('product_type_id')->label('Vrsta kataloga')
                ->helperText('Grupa proizvoda sa zajedničkim atributima, npr. odjeća. Novi proizvod sprema se kao nacrt.')
                ->options(fn () => ProductType::query()->orderBy('name')->pluck('name', 'id'))
                ->required()->searchable()
                ->createOptionForm([TextInput::make('name')->label('Naziv vrste')->required()->maxLength(255)])
                ->createOptionUsing(function (array $data): int {
                    Gate::authorize('create', Product::class);

                    return ProductType::query()->create(['name' => $data['name']])->id;
                }),
            Select::make('option_ids')->label('Opcije varijanti')->multiple()->default([])->maxItems(10)
                ->options(fn () => ProductOption::query()->shared()->get()->mapWithKeys(fn (ProductOption $option) => [$option->id => $option->translate('name', config('oneshop.primary_locale'))]))
                ->helperText('Pripremite opcije i vrijednosti u izborniku Opcije. Svaki SKU odabire jednu vrijednost po opciji.'),
            Select::make('collection_ids')->label('Kategorije')->multiple()->default([])->maxItems(100)->searchable()
                ->options(fn () => Collection::query()->where('type', 'static')->with('group')->get()->mapWithKeys(function (Model $category): array {
                    if (! $category instanceof Collection) {
                        throw new \LogicException('Unsupported category model.');
                    }

                    return [$category->id => $category->getRelationValue('group')?->getAttribute('name').' / '.$category->translateAttribute('name', config('oneshop.primary_locale'))];
                }))
                ->helperText('Pripremite ručne kategorije u izborniku Kategorije. Automatske kolekcije se ne mijenjaju.'),
            Tabs::make('Prijevodi')->tabs($tabs)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'media' => fn ($images) => $images->where('collection_name', config('lunar.media.collection'))
                    ->where('disk', 'public')->orderBy('order_column')->orderBy('id'),
            ]))
            ->columns([
                ImageColumn::make('cover')->label('Fotografija')->square()->imageSize(48)
                    ->state(function (Product $record): ?string {
                        $image = self::coverImage($record);
                        $source = $image ? ProductImages::storefrontSources($image)['thumbnail'] : StoreCatalog::demoImage($record);

                        return $source ? url($source) : null;
                    })
                    ->alt(fn (Product $record): string => ProductImages::alternativeText(self::coverImage($record), (string) $record->translateAttribute('name', config('oneshop.primary_locale')))),
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Naziv')
                    ->searchable(query: fn (Builder $query, string $search): Builder => app(ProductSearch::class)->apply($query, $search))
                    ->state(fn (Product $record) => $record->translateAttribute('name', config('oneshop.primary_locale'))),
                TextColumn::make('productType.name')->label('Vrsta')->sortable(),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    'draft' => 'Nacrt', 'published' => 'Objavljeno', default => $state
                }),
                TextColumn::make('variants_count')->counts('variants')->label('SKU-ovi'),
                TextColumn::make('updated_at')->label('Ažurirano')->dateTime('d.m.Y. H:i', timezone: ShopTime::zone())->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(['draft' => 'Nacrt', 'published' => 'Objavljeno']),
            ])
            ->recordActions([
                Action::make('checkReadiness')->label('Provjera')->icon('heroicon-o-clipboard-document-check')
                    ->modalHeading('Provjera podataka za objavu')
                    ->modalDescription('Pregled spremljenih podataka potrebnih za objavu proizvoda.')
                    ->authorize(fn (Product $record) => Gate::allows('view', $record))
                    ->schema([
                        TextEntry::make('readiness')->label('Rezultat')
                            ->state(fn (Product $record) => app(ProductPublication::class)->issues($record) ?: ['Podaci potrebni za objavu su popunjeni.'])->listWithLineBreaks(),
                    ])->modalSubmitAction(false)->modalCancelActionLabel('Zatvori'),
                EditAction::make(),
            ])
            ->searchPlaceholder('Naziv proizvoda ili aktivni SKU')
            ->defaultSort('id', 'desc');
    }

    private static function coverImage(Product $record): ?Media
    {
        $images = $record->getRelationValue('media');
        $image = $images instanceof \Illuminate\Support\Collection ? $images->first() : null;

        return $image instanceof Media ? $image : null;
    }

    public static function getRelations(): array
    {
        return [VariantsRelationManager::class, ImagesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
