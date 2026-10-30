<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Orders\OrderMoney;
use App\Orders\PaymentLabels;
use App\Support\ShopTime;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Lunar\Models\Transaction;

class TransactionsRelationManager extends OrderRelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Transakcije';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('order.currency'))
            ->columns([
                TextColumn::make('created_at')->label('Vrijeme')->dateTime('d.m.Y. H:i', timezone: ShopTime::zone()),
                TextColumn::make('type')->label('Vrsta')->formatStateUsing(fn (string $state) => PaymentLabels::type($state)),
                TextColumn::make('driver')->label('Sustav naplate')->formatStateUsing(fn (string $state) => PaymentLabels::driver($state)),
                TextColumn::make('reference')->label('Referenca'),
                TextColumn::make('status')->label('Status')->state(fn (Transaction $record) => PaymentLabels::status($record)),
                IconColumn::make('success')->label('Uspjeh')->boolean(),
                TextColumn::make('amount')->label('Iznos')->state(fn (Transaction $record) => OrderMoney::format($record, 'amount')),
            ]);
    }
}
