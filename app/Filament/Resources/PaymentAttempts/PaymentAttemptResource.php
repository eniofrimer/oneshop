<?php

namespace App\Filament\Resources\PaymentAttempts;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PaymentAttempts\Pages\ListPaymentAttempts;
use App\Filament\Resources\PaymentAttempts\Pages\ViewPaymentAttempt;
use App\Filament\Support\ResolvesUuidRecords;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Payments\PaymentAttemptLabels;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<PaymentAttempt> */
class PaymentAttemptResource extends Resource
{
    use ResolvesUuidRecords;

    protected static ?string $model = PaymentAttempt::class;

    protected static ?string $modelLabel = 'pokušaj naplate';

    protected static ?string $pluralModelLabel = 'Pokušaji naplate';

    /** @return Builder<PaymentAttempt> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('order', fn ($query) => $query->whereNotNull('placed_at'))->with(['order', 'receipts']);
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
        return $table->description('Stripe sandbox: spremljeni pokušaji i potvrde procesora. Stvarni novac, ručna potvrda i povrati nisu uključeni.')
            ->columns([
                TextColumn::make('created_at')->label('Vrijeme')->dateTime('d.m.Y. H:i')->sortable(),
                TextColumn::make('order.reference')->label('Narudžba')->searchable()
                    ->url(fn (PaymentAttempt $record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state) => PaymentAttemptLabels::STATUSES[$state] ?? $state),
                TextColumn::make('amount_minor')->label('Očekivani iznos')
                    ->state(fn (PaymentAttempt $record) => PaymentAttemptLabels::money($record->amount_minor, $record->currency_code)),
                TextColumn::make('provider_account')->label('Stripe testni račun')->toggleable(isToggledHiddenByDefault: true),
            ])->filters([SelectFilter::make('status')->label('Status')->options(PaymentAttemptLabels::STATUSES)])
            ->recordActions([ViewAction::make()])->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stripe sandbox pokušaj')->description('Pregled je samo za čitanje. Nepoznat ishod zadržava rezervaciju; ovaj ekran ne proglašava naplatu uspješnom.')->schema([
                TextEntry::make('id')->label('ID pokušaja'),
                TextEntry::make('order.reference')->label('Narudžba')
                    ->url(fn (PaymentAttempt $record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextEntry::make('status')->label('Status')->formatStateUsing(fn (string $state) => PaymentAttemptLabels::STATUSES[$state] ?? $state),
                TextEntry::make('amount_minor')->label('Očekivani iznos')
                    ->state(fn (PaymentAttempt $record) => PaymentAttemptLabels::money($record->amount_minor, $record->currency_code)),
                TextEntry::make('provider_account')->label('Stripe testni račun'),
                TextEntry::make('provider_object_id')->label('Checkout Session')->placeholder('Još nije vezan'),
                TextEntry::make('last_checked_at')->label('Zadnja provjera')->dateTime('d.m.Y. H:i:s')->placeholder('Još nije provjereno'),
                TextEntry::make('review_reason')->label('Potrebna provjera')->state(fn (PaymentAttempt $record) => PaymentAttemptLabels::reason($record->review_reason)),
            ])->columns(2),
            Section::make('Potvrde stvarnog stanja u testnom procesoru')->description('Potvrda novca i dodjela zalihe su zasebne činjenice. Valuta potvrde prikazuje se neovisno o narudžbi.')->schema([
                RepeatableEntry::make('receipts')->label('Potvrde')->schema([
                    TextEntry::make('capture_reference')->label('Capture'),
                    TextEntry::make('payment_reference')->label('PaymentIntent'),
                    TextEntry::make('amount_minor')->label('Potvrđeni iznos')
                        ->state(fn (PaymentReceipt $record) => PaymentAttemptLabels::money($record->amount_minor, $record->currency_code)),
                    TextEntry::make('allocation_status')->label('Dodjela zalihe')->formatStateUsing(fn (string $state) => match ($state) {
                        'allocated' => 'Zaliha dodijeljena', 'review' => 'Potrebna provjera', default => 'Čeka dodjelu',
                    }),
                    TextEntry::make('review_reason')->label('Napomena')->state(fn (PaymentReceipt $record) => PaymentAttemptLabels::reason($record->review_reason)),
                ])->columns(2),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentAttempts::route('/'), 'view' => ViewPaymentAttempt::route('/{record}')];
    }
}
