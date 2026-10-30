<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Catalog\ProductOptions;
use App\Catalog\VariantEditor;
use App\Filament\Support\CatalogValidation;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Currency;
use Lunar\Models\Price;
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

    private function changeArchive(ProductVariant $record, bool $archived, Action $action): void
    {
        try {
            app(VariantEditor::class)->setArchived($this->product(), $record, $archived);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Arhiva SKU-a nije promijenjena')
                ->body(e(collect($exception->errors())->flatten()->implode(' ')))->persistent()->send();
            $action->halt();
        }
        $this->flushCachedTableRecords();
    }

    private static function basePriceLabel(ProductVariant $record): string
    {
        if ($record->unit_quantity !== 1 || $record->min_quantity !== 1 || $record->quantity_increment !== 1) {
            return 'Posebno pakiranje';
        }
        $prices = $record->getRelationValue('prices');
        if (! $prices instanceof Collection || $prices->isEmpty()) {
            return 'Nije postavljena';
        }
        $price = $prices->count() === 1 ? $prices->first() : null;
        if (! $price instanceof Price) {
            return 'Provjeri cijene';
        }
        $currency = $price->getRelationValue('currency');
        if (! $currency instanceof Currency || $currency->code !== 'EUR' || $currency->decimal_places !== 2) {
            return 'Provjeri valutu';
        }
        $minor = $price->getRawOriginal('price');
        if ((! is_int($minor) && ! is_string($minor)) || ! preg_match('/^\d+$/D', (string) $minor)) {
            return 'Provjeri cijene';
        }

        return str_replace('.', ',', (string) BigDecimal::ofUnscaledValue($minor, 2)).' EUR';
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel('SKU')->pluralModelLabel('SKU-ovi')
            ->modifyQueryUsing(fn ($query) => $query->withoutGlobalScopes([SoftDeletingScope::class])->with([
                'prices' => fn ($prices) => $prices->whereNull('customer_group_id')->where('min_quantity', 1)
                    ->whereHas('currency', fn ($currency) => $currency->where('code', 'EUR'))->with('currency'),
            ]))
            ->filters([TrashedFilter::make()->label('Arhivirani SKU-ovi')])
            ->recordTitleAttribute('sku')
            ->columns([
                TextColumn::make('sku')->label('SKU')->searchable()->sortable()->wrap(),
                TextColumn::make('combination')->label('Kombinacija')
                    ->state(fn (ProductVariant $record) => $record->getOption()),
                TextColumn::make('base_price_eur')->label(config('lunar.pricing.stored_inclusive_of_tax') ? 'Cijena s porezom' : 'Cijena bez poreza')
                    ->state(fn (ProductVariant $record) => self::basePriceLabel($record)),
                TextColumn::make('stock')->label('Zaliha')->sortable(),
                TextColumn::make('purchasable')->label('Dostupnost')->wrap()->formatStateUsing(fn (string $state) => match ($state) {
                    'in_stock' => 'Samo raspoloživa zaliha', 'always' => 'Bez ograničenja zalihom', default => $state,
                }),
                IconColumn::make('shippable')->label('Fizička dostava')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label('Dodaj SKU')
                    ->using(fn (array $data): ProductVariant => CatalogValidation::run($this->getMountedActionSchema(), fn () => app(VariantEditor::class)->save($this->product(), $data))),
            ])
            ->recordActions([
                Action::make('archive')->label('Arhiviraj SKU')->requiresConfirmation()
                    ->disabled(fn () => $this->product()->status !== 'draft')->tooltip('Arhiva se mijenja dok je proizvod nacrt.')
                    ->modalDescription('Najprije vratite proizvod u nacrt. Cijene, opcije, slike i datoteke ostaju sačuvane.')
                    ->visible(fn (ProductVariant $record) => ! $record->trashed())
                    ->authorize(fn () => Gate::allows('archive', $this->product()))
                    ->action(fn (ProductVariant $record, Action $action) => $this->changeArchive($record, true, $action)),
                Action::make('restore')->label('Vrati SKU')->requiresConfirmation()
                    ->disabled(fn () => $this->product()->status !== 'draft')->tooltip('Arhiva se mijenja dok je proizvod nacrt.')
                    ->visible(fn (ProductVariant $record) => $record->trashed())
                    ->authorize(fn () => Gate::allows('archive', $this->product()))
                    ->action(fn (ProductVariant $record, Action $action) => $this->changeArchive($record, false, $action)),
                EditAction::make()->visible(fn (ProductVariant $record) => ! $record->trashed())
                    ->mutateRecordDataUsing(fn (ProductVariant $record): array => VariantEditor::formData($record))
                    ->using(fn (ProductVariant $record, array $data): ProductVariant => CatalogValidation::run($this->getMountedActionSchema(), fn () => app(VariantEditor::class)->save($this->product(), $data, $record))),
            ]);
    }
}
