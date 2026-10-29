<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Orders\OrderMoney;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Lunar\Models\OrderLine;

class LinesRelationManager extends OrderRelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Stavke narudžbe';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('order.currency'))
            ->columns([
                TextColumn::make('identifier')->label('SKU / oznaka'),
                TextColumn::make('description')->label('Spremljeni naziv'),
                TextColumn::make('option')->label('Opcija')->placeholder('—'),
                TextColumn::make('type')->label('Vrsta')->formatStateUsing(fn (string $state) => match ($state) {
                    'physical' => 'Fizički proizvod', 'digital' => 'Digitalni proizvod', 'shipping' => 'Dostava', default => $state,
                }),
                TextColumn::make('quantity')->label('Količina'),
                TextColumn::make('unit_quantity')->label('Obračunska jedinica')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('unit_price')->label('Cijena')->state(fn (OrderLine $record) => OrderMoney::format($record, 'unit_price')),
                TextColumn::make('discount_total')->label('Popust')->state(fn (OrderLine $record) => OrderMoney::format($record, 'discount_total')),
                TextColumn::make('tax_total')->label('Porez')->state(fn (OrderLine $record) => OrderMoney::format($record, 'tax_total')),
                TextColumn::make('total')->label('Ukupno')->state(fn (OrderLine $record) => OrderMoney::format($record, 'total')),
            ]);
    }
}
