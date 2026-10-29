<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Catalog\ProductOptions;
use App\Catalog\VariantEditor;
use App\Filament\Support\CatalogValidation;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Lunar\Models\Product;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'SKU-ovi, cijene i zaliha';

    public function form(Schema $schema): Schema
    {
        $optionFields = [];
        foreach (ProductOptions::forProduct($this->product()) as $option) {
            $optionFields[] = Select::make('option_values.'.$option->id)
                ->label((string) $option->translate('name', config('oneshop.primary_locale')))
                ->options(ProductOptionValue::query()->where('product_option_id', $option->id)->orderBy('position')->get()
                    ->mapWithKeys(fn (ProductOptionValue $value) => [$value->id => $value->translate('name', config('oneshop.primary_locale'))]))
                ->required();
        }

        return $schema->components([
            ...$optionFields,
            TextInput::make('sku')->label('SKU')->required()->maxLength(100)
                ->helperText('Jedinstvena oznaka: slova, brojevi, točka, crtica ili podvlaka. Sprema se velikim slovima.'),
            TextInput::make('price_eur')->label(config('lunar.pricing.stored_inclusive_of_tax') ? 'Cijena s porezom' : 'Cijena bez poreza')
                ->suffix('EUR')->required()->inputMode('decimal')
                ->helperText('Osnovna cijena za jedan komad. Najviše dvije decimale; bez separatora tisućica.'),
            Select::make('tax_class_id')->label('Porezna kategorija')->required()
                ->options(fn () => TaxClass::query()->orderBy('name')->pluck('name', 'id'))
                ->helperText('Kategorija ne postavlja porezne stope. Stope i zone konfiguriraju se zasebno.')
                ->createOptionForm([TextInput::make('name')->label('Naziv kategorije')->required()->maxLength(255)])
                ->createOptionUsing(function (array $data): int {
                    Gate::authorize('update', $this->product());

                    return TaxClass::query()->create(['name' => $data['name'], 'default' => false])->id;
                }),
            TextInput::make('stock')->label('Zaliha')->integer()->minValue(0)->maxValue(2147483647)->default(0)->required(),
            Hidden::make('expected_stock'),
            Select::make('purchasable')->label('Dostupnost za kupnju')->required()->default('in_stock')
                ->options(['in_stock' => 'Samo raspoloživa zaliha', 'always' => 'Bez ograničenja zalihom']),
            Toggle::make('shippable')->label('Zahtijeva fizičku dostavu')->default(true)->required()
                ->helperText('Isključeno označava digitalnu stavku. Privatne datoteke i pristup nakon naplate dodaju se zasebno.'),
        ]);
    }

    private function product(): Product
    {
        $product = $this->getOwnerRecord();
        abort_unless($product instanceof Product, 404);

        return $product;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                TextColumn::make('sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('combination')->label('Kombinacija')
                    ->state(fn (ProductVariant $record) => $record->getOption()),
                TextColumn::make('stock')->label('Zaliha')->sortable(),
                TextColumn::make('purchasable')->label('Dostupnost'),
                IconColumn::make('shippable')->label('Fizička dostava')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label('Dodaj SKU')
                    ->using(fn (array $data): ProductVariant => CatalogValidation::run($this->getMountedActionSchema(), fn () => app(VariantEditor::class)->save($this->product(), $data))),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (ProductVariant $record): array => VariantEditor::formData($record))
                    ->using(fn (ProductVariant $record, array $data): ProductVariant => CatalogValidation::run($this->getMountedActionSchema(), fn () => app(VariantEditor::class)->save($this->product(), $data, $record))),
            ]);
    }
}
