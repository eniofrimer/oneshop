<?php

namespace App\Filament\Resources\Products;

use App\Catalog\ProductEditor;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\ImagesRelationManager;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductType;

/** @extends resource<Product> */
class ProductResource extends Resource
{
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
            Tabs::make('Prijevodi')->tabs($tabs)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Naziv')
                    ->state(fn (Product $record) => $record->translateAttribute('name', config('oneshop.primary_locale'))),
                TextColumn::make('productType.name')->label('Vrsta')->sortable(),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('variants_count')->counts('variants')->label('SKU-ovi'),
                TextColumn::make('updated_at')->label('Ažurirano')->dateTime('d.m.Y. H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(['draft' => 'Nacrt', 'published' => 'Objavljeno']),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('id', 'desc');
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
