<?php

namespace App\Filament\Resources\ShippingMethods;

use App\Filament\Resources\ShippingMethods\Pages\CreateShippingMethod;
use App\Filament\Resources\ShippingMethods\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethods\Pages\ListShippingMethods;
use App\Filament\Support\ResolvesNumericRecords;
use App\Models\ShippingMethod;
use App\Storefront\StoreCatalog;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Lunar\Models\Country;
use Lunar\Models\TaxClass;

/** @extends resource<ShippingMethod> */
class ShippingMethodResource extends Resource
{
    use ResolvesNumericRecords;

    protected static ?string $model = ShippingMethod::class;

    protected static ?string $modelLabel = 'način dostave';

    protected static ?string $pluralModelLabel = 'Dostava';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Naziv dostave')->required()->maxLength(120),
            Toggle::make('enabled')->label('Dostupno na checkoutu')->default(false),
            Textarea::make('description')->label('Opis za kupca')->maxLength(500)->columnSpanFull(),
            Select::make('countries')->label('Države dostave')->multiple()->required()->searchable()
                ->options(fn () => Country::query()->whereNotNull('iso2')->orderBy('name')->get()
                    ->mapWithKeys(fn (Country $country) => [$country->iso2 => $country->iso2 === 'HR' ? 'Hrvatska' : $country->name])->all()),
            Select::make('tax_class_id')->label('Porezna klasa')->required()->options(fn () => TaxClass::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('price_eur')->label('Cijena dostave (EUR)')->required()->inputMode('decimal')
                ->helperText(config('lunar.pricing.stored_inclusive_of_tax') ? 'Unesite iznos s porezom prema postavci trgovine.' : 'Unesite osnovni iznos bez poreza. Lunar izračunava porez na checkoutu.'),
            Hidden::make('version'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Naziv')->searchable(),
            IconColumn::make('enabled')->label('Aktivno')->boolean(),
            TextColumn::make('countries')->label('Države')->badge(),
            TextColumn::make('price_minor')->label('Osnovna cijena')->formatStateUsing(fn (int $state) => StoreCatalog::money($state)),
            TextColumn::make('taxClass.name')->label('Porezna klasa'),
        ])->recordActions([EditAction::make()])->defaultSort('name');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListShippingMethods::route('/'), 'create' => CreateShippingMethod::route('/create'), 'edit' => EditShippingMethod::route('/{record}/edit')];
    }
}
