<?php

namespace App\Filament\Resources\DigitalAssets;

use App\Digital\DigitalAssetEditor;
use App\Filament\Resources\DigitalAssets\Pages\CreateDigitalAsset;
use App\Filament\Resources\DigitalAssets\Pages\ListDigitalAssets;
use App\Models\DigitalAsset;
use App\Support\ShopTime;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Lunar\Models\ProductVariant;

/** @extends resource<DigitalAsset> */
class DigitalAssetResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?int $navigationSort = 40;

    protected static ?string $model = DigitalAsset::class;

    protected static ?string $modelLabel = 'privatna datoteka';

    protected static ?string $pluralModelLabel = 'Digitalne datoteke';

    protected static bool $hasTitleCaseModelLabel = false;

    /** @return Builder<DigitalAsset> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('variant.product');
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_variant_id')->label('Digitalni SKU')->required()->searchable()
                ->getSearchResultsUsing(function (string $search) {
                    $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtoupper(trim($search)));

                    return ProductVariant::query()->where('shippable', false)->whereRaw('UPPER(TRIM(sku)) LIKE ?', [$prefix.'%'])->orderBy('sku')->limit(50)->pluck('sku', 'id');
                })
                ->getOptionLabelUsing(fn ($value) => ProductVariant::query()->where('shippable', false)->find($value)?->sku)
                ->helperText('Upišite početak SKU oznake. Dostupne su varijante bez fizičke dostave.'),
            TextInput::make('label')->label('Naziv datoteke')->required()->maxLength(255),
            FileUpload::make('file')->label('Privatna datoteka')->required()->disk('private')->visibility('private')
                ->maxSize(DigitalAssetEditor::MAX_KILOBYTES)->storeFiles(false)->previewable(false)
                ->helperText('Do 10 MB. Nova datoteka čuva se odvojeno; prethodna se može arhivirati. Ovaj upload ne daje kupcu automatski pristup.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->description('Privatne datoteke digitalnih SKU-ova. Arhiviranje zadržava sadržaj; upload nove datoteke ne prepisuje postojeću.')
            ->columns([
                TextColumn::make('label')->label('Naziv')->searchable(),
                TextColumn::make('variant.sku')->label('SKU')->searchable(),
                TextColumn::make('original_name')->label('Izvorni naziv'),
                TextColumn::make('size')->label('Veličina')->numeric()->suffix(' B'),
                TextColumn::make('archived_at')->label('Arhivirano')->dateTime('d.m.Y. H:i', timezone: ShopTime::zone())->placeholder('Aktivno'),
                TextColumn::make('created_at')->label('Dodano')->dateTime('d.m.Y. H:i', timezone: ShopTime::zone()),
            ])->filters([
                TernaryFilter::make('archived_at')->label('Arhivirano')->nullable(),
            ])->recordActions([
                Action::make('download')->label('Preuzmi')->authorize(fn (DigitalAsset $record) => Gate::allows('view', $record))
                    ->action(fn (DigitalAsset $record) => app(DigitalAssetEditor::class)->download($record)),
                Action::make('archive')->label('Arhiviraj')->visible(fn (DigitalAsset $record) => $record->archived_at === null)
                    ->authorize(fn (DigitalAsset $record) => Gate::allows('update', $record))
                    ->action(fn (DigitalAsset $record) => app(DigitalAssetEditor::class)->archive($record, true)),
                Action::make('restore')->label('Vrati u aktivne')->visible(fn (DigitalAsset $record) => $record->archived_at !== null)
                    ->authorize(fn (DigitalAsset $record) => Gate::allows('update', $record))
                    ->action(fn (DigitalAsset $record) => app(DigitalAssetEditor::class)->archive($record, false)),
            ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListDigitalAssets::route('/'), 'create' => CreateDigitalAsset::route('/create')];
    }
}
