<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Support\ResolvesNumericRecords;
use App\Orders\OrderMoney;
use App\Orders\PaymentLabels;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lunar\Models\Transaction;

/** @extends resource<Transaction> */
class PaymentResource extends Resource
{
    use ResolvesNumericRecords;

    protected static ?string $model = Transaction::class;

    protected static ?string $modelLabel = 'plaćanje';

    protected static ?string $pluralModelLabel = 'Plaćanja';

    protected static ?string $recordTitleAttribute = 'reference';

    /** @return Builder<Transaction> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('order', fn ($query) => $query->whereNotNull('placed_at'))->with('order.currency');
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
        return $table->description('Povijest pokušaja plaćanja. Testne simulacije ne predstavljaju stvarnu naplatu. Povrat novca i stvarni procesori još nisu uključeni.')
            ->columns([
                TextColumn::make('created_at')->label('Vrijeme')->dateTime('d.m.Y. H:i')->sortable(),
                TextColumn::make('order.reference')->label('Narudžba')->searchable()
                    ->url(fn (Transaction $record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextColumn::make('driver')->label('Sustav')->formatStateUsing(fn (string $state) => PaymentLabels::driver($state)),
                TextColumn::make('status')->label('Status')->badge()->state(fn (Transaction $record) => PaymentLabels::status($record)),
                IconColumn::make('success')->label('Uspjeh')->boolean(),
                TextColumn::make('amount')->label('Iznos')->state(fn (Transaction $record) => OrderMoney::format($record, 'amount')),
            ])->filters([
                SelectFilter::make('driver')->label('Sustav')->options(['oneshop-demo' => 'Testna simulacija']),
                TernaryFilter::make('success')->label('Uspjeh')->placeholder('Svi pokušaji')
                    ->trueLabel('Uspješni pokušaji')->falseLabel('Neuspješni pokušaji'),
            ])
            ->recordActions([ViewAction::make()])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Zapis plaćanja')->description('Pregled spremljenog zapisa, bez pristupnih podataka ili kartičnih podataka.')->schema([
            TextEntry::make('order.reference')->label('Narudžba')
                ->url(fn (Transaction $record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
            TextEntry::make('created_at')->label('Vrijeme')->dateTime('d.m.Y. H:i'),
            TextEntry::make('driver')->label('Sustav')->formatStateUsing(fn (string $state) => PaymentLabels::driver($state)),
            TextEntry::make('type')->label('Vrsta')->formatStateUsing(fn (string $state) => PaymentLabels::type($state)),
            TextEntry::make('status')->label('Status')->state(fn (Transaction $record) => PaymentLabels::status($record)),
            IconEntry::make('success')->label('Uspjeh')->boolean(),
            TextEntry::make('amount')->label('Iznos')->state(fn (Transaction $record) => OrderMoney::format($record, 'amount')),
            TextEntry::make('reference')->label('Referenca'),
        ])->columns(2)]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/'), 'view' => ViewPayment::route('/{record}')];
    }
}
