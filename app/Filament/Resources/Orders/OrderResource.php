<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\LinesRelationManager;
use App\Filament\Resources\Orders\RelationManagers\TransactionsRelationManager;
use App\Filament\Support\ResolvesNumericRecords;
use App\Orders\OrderLabels;
use App\Orders\OrderMoney;
use App\Support\ShopTime;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lunar\Models\Order;

/** @extends resource<Order> */
class OrderResource extends Resource
{
    use ResolvesNumericRecords;

    protected static string|\UnitEnum|null $navigationGroup = 'Prodaja';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 10;

    protected static ?string $model = Order::class;

    protected static ?string $modelLabel = 'narudžba';

    protected static ?string $pluralModelLabel = 'Narudžbe';

    protected static ?string $recordTitleAttribute = 'reference';

    /** @return Builder<Order> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('placed_at')->with(['currency', 'billingAddress', 'shippingAddress']);
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

    /** @return array<string, string> */
    private static function statuses(): array
    {
        $statuses = config('lunar.orders.statuses', []);
        if (! is_array($statuses)) {
            throw new \LogicException('Order statuses must be configured as an array.');
        }
        $labels = [];
        foreach ($statuses as $key => $status) {
            if (is_string($key)) {
                $labels[$key] = is_array($status) && is_string($status['label'] ?? null) ? $status['label'] : $key;
            }
        }

        return $labels;
    }

    public static function table(Table $table): Table
    {
        return $table->description('Zaprimljene narudžbe i njihove povijesne vrijednosti. Nedovršene košarice nisu u ovom popisu.')
            ->columns([
                TextColumn::make('reference')->label('Broj narudžbe')->searchable()->sortable(),
                TextColumn::make('mode')->label('Način')->state(fn (Order $record) => OrderLabels::mode($record))->badge(),
                TextColumn::make('placed_at')->label('Zaprimljeno')->dateTime('d.m.Y. H:i', timezone: ShopTime::zone())->sortable(),
                TextColumn::make('status')->label('Status')->badge()->color(fn (Order $record) => OrderLabels::color($record))->state(fn (Order $record) => OrderLabels::status($record)),
                TextColumn::make('billingAddress.full_name')->label('Kupac'),
                TextColumn::make('billingAddress.contact_email')->label('E-pošta')->searchable(),
                TextColumn::make('total')->label('Ukupno')->state(fn (Order $record) => OrderMoney::format($record, 'total')),
            ])->filters([
                SelectFilter::make('status')->label('Status')->options(self::statuses()),
                SelectFilter::make('mode')->label('Način kupnje')->options([
                    'demo' => 'Testna simulacija', 'stripe-test' => 'Stripe sandbox', 'standard' => 'Standard',
                ])->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'demo', 'stripe-test' => $query->where('meta->oneshop_mode', $data['value']),
                        'standard' => $query->where(fn (Builder $query) => $query->whereNull('meta->oneshop_mode')
                            ->orWhereNotIn('meta->oneshop_mode', ['demo', 'stripe-test'])),
                        default => $query,
                    };
                }),
            ])->recordUrl(fn (Order $record) => self::getUrl('view', ['record' => $record]))
            ->recordActions([ViewAction::make()])->defaultSort('placed_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        $totals = [];
        foreach (['sub_total' => 'Stavke', 'discount_total' => 'Popust', 'shipping_total' => 'Dostava', 'tax_total' => 'Porez', 'total' => 'Ukupno'] as $field => $label) {
            $totals[] = TextEntry::make($field)->label($label)->state(fn (Order $record) => OrderMoney::format($record, $field));
        }
        $addresses = [];
        foreach (['billingAddress' => 'Podaci za račun', 'shippingAddress' => 'Podaci za dostavu'] as $relation => $title) {
            $addresses[] = Section::make($title)->schema([
                TextEntry::make($relation.'.full_name')->label('Ime i prezime')->placeholder('—'),
                TextEntry::make($relation.'.company_name')->label('Tvrtka')->placeholder('—'),
                TextEntry::make($relation.'.tax_identifier')->label('Porezni broj')->placeholder('—'),
                TextEntry::make($relation.'.line_one')->label('Adresa')->placeholder('—'),
                TextEntry::make($relation.'.line_two')->label('Dodatak adresi')->placeholder('—'),
                TextEntry::make($relation.'.postcode')->label('Poštanski broj')->placeholder('—'),
                TextEntry::make($relation.'.city')->label('Grad')->placeholder('—'),
                TextEntry::make($relation.'.state')->label('Županija / regija')->placeholder('—'),
                TextEntry::make($relation.'.country.name')->label('Država')->placeholder('—'),
                TextEntry::make($relation.'.contact_email')->label('E-pošta')->placeholder('—'),
                TextEntry::make($relation.'.contact_phone')->label('Telefon')->placeholder('—'),
                TextEntry::make($relation.'.delivery_instructions')->label('Upute')->placeholder('—'),
            ])->columns(2);
        }

        return $schema->components([
            Section::make('Narudžba')->schema([
                TextEntry::make('reference')->label('Broj narudžbe'),
                TextEntry::make('mode')->label('Način')->state(fn (Order $record) => OrderLabels::description($record)),
                TextEntry::make('placed_at')->label('Zaprimljeno')->dateTime('d.m.Y. H:i', timezone: ShopTime::zone()),
                TextEntry::make('status')->label('Status')->state(fn (Order $record) => OrderLabels::status($record)),
                TextEntry::make('customer_reference')->label('Referenca kupca')->placeholder('—'),
                TextEntry::make('notes')->label('Napomena')->placeholder('—'),
            ])->columns(2),
            Section::make('Spremljeni iznosi')->schema($totals)->columns(3),
            ...$addresses,
        ]);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class, TransactionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/'), 'view' => ViewOrder::route('/{record}')];
    }
}
