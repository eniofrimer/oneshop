<?php

namespace App\Filament\Resources\Activities;

use App\Audit\AuditEvents;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\User;
use App\Support\ShopTime;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Gate;
use Lunar\Models\Order;
use Spatie\Activitylog\Models\Activity;

/** @extends resource<Activity> */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $modelLabel = 'aktivnost';

    protected static ?string $pluralModelLabel = 'Aktivnosti';

    protected static string|\UnitEnum|null $navigationGroup = 'Postavke';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 20;

    protected static bool $isGloballySearchable = false;

    /** @return Builder<Activity> */
    public static function getEloquentQuery(): Builder
    {
        Gate::authorize('viewAny', Activity::class);
        $table = (new Activity)->getTable();
        $query = parent::getEloquentQuery()
            ->leftJoin((new Order)->getTable().' as audit_order', fn (JoinClause $join) => $join
                ->on('audit_order.id', $table.'.subject_id')->where($table.'.subject_type', (new Order)->getMorphClass()))
            ->leftJoin((new User)->getTable().' as audit_actor', fn (JoinClause $join) => $join
                ->on('audit_actor.id', $table.'.causer_id')->where($table.'.causer_type', (new User)->getMorphClass()))
            // Never hydrate free-text descriptions, arbitrary properties, private
            // paths, reservation capabilities or authentication payloads.
            ->select(array_map(fn (string $column) => $table.'.'.$column,
                ['id', 'event', 'log_name', 'subject_type', 'subject_id', 'causer_id', 'causer_type', 'created_at']))
            ->addSelect(['audit_order.reference as order_reference', 'audit_actor.name as actor_name']);

        return AuditEvents::visible($query);
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
        $activityTable = (new Activity)->getTable();

        return $table->description('Pregled zabilježenih promjena kataloga, dostave, privatnih datoteka i rezervacija. Vrijeme: '.ShopTime::zone().'.')
            ->columns([
                TextColumn::make('created_at')->label('Vrijeme')->dateTime('d.m.Y. H:i:s', timezone: ShopTime::zone())
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy($activityTable.'.created_at', $direction)),
                TextColumn::make('event')->label('Događaj')->state(fn (Activity $record) => AuditEvents::LABELS[$record->event] ?? 'Promjena'),
                TextColumn::make('subject_kind')->label('Područje')->state(fn (Activity $record) => AuditEvents::subject((string) $record->subject_type)),
                TextColumn::make('subject_id')->label('Oznaka zapisa')->formatStateUsing(fn ($state) => '#'.$state),
                TextColumn::make('order_reference')->label('Narudžba')->placeholder('—')
                    ->url(fn (Activity $record) => $record->getAttribute('order_reference') === null ? null
                        : OrderResource::getUrl('view', ['record' => $record->subject_id])),
                TextColumn::make('actor_name')->label('Zabilježio')->state(fn (Activity $record) => is_string($record->getAttribute('actor_name'))
                    ? $record->getAttribute('actor_name') : ($record->causer_id === null ? 'Automatski' : 'Autor nije dostupan')),
            ])->filters([
                SelectFilter::make('event')->label('Događaj')->options(AuditEvents::LABELS),
            ])->defaultSort(fn (Builder $query) => $query->orderByDesc($activityTable.'.created_at')->orderByDesc($activityTable.'.id'))
            ->emptyStateHeading('Još nema zabilježenih aktivnosti')
            ->emptyStateDescription('Ovdje se prikazuju podržani događaji od trenutka kada su zabilježeni.');
    }

    public static function getPages(): array
    {
        return ['index' => ListActivities::route('/')];
    }
}
