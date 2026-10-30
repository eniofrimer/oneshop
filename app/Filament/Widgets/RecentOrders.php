<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Orders\OrderLabels;
use App\Orders\OrderMoney;
use App\Support\ShopTime;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Gate;
use Lunar\Models\Order;

class RecentOrders extends TableWidget
{
    protected static ?int $sort = 1;

    protected static ?bool $isLazyLoaded = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Order::class);
    }

    public function table(Table $table): Table
    {
        Gate::authorize('viewAny', Order::class);

        return $table->heading('Posljednje narudžbe')
            ->description('Otvori narudžbu za stavke, plaćanje i spremljene podatke kupca.')
            ->query(OrderResource::getEloquentQuery())
            ->columns([
                TextColumn::make('reference')->label('Narudžba')->weight('medium'),
                TextColumn::make('placed_at')->label('Zaprimljeno')->dateTime('d.m.Y. H:i', timezone: ShopTime::zone()),
                TextColumn::make('billingAddress.full_name')->label('Kupac')->placeholder('—'),
                TextColumn::make('mode')->label('Način')->badge()->color('gray')->state(fn (Order $record) => OrderLabels::mode($record)),
                TextColumn::make('status')->label('Status')->badge()->color(fn (Order $record) => OrderLabels::color($record))
                    ->state(fn (Order $record) => OrderLabels::status($record)),
                TextColumn::make('total')->label('Ukupno')->state(fn (Order $record) => OrderMoney::format($record, 'total')),
            ])
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]))
            ->defaultSort('placed_at', 'desc')->paginationPageOptions([5])->defaultPaginationPageOption(5)
            ->emptyStateHeading('Prva narudžba počinje u trgovini')
            ->emptyStateDescription('Predane narudžbe pojavit će se ovdje. Nedovršene košarice ostaju izvan ovog popisa.')
            ->emptyStateIcon('heroicon-o-shopping-bag');
    }
}
