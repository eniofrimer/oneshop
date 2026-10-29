<?php

namespace App\Filament\Resources\Options;

use App\Catalog\ProductEditor;
use App\Filament\Resources\Options\Pages\CreateOption;
use App\Filament\Resources\Options\Pages\EditOption;
use App\Filament\Resources\Options\Pages\ListOptions;
use App\Filament\Resources\Options\RelationManagers\ValuesRelationManager;
use App\Filament\Support\ResolvesNumericRecords;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Lunar\Models\ProductOption;

/** @extends resource<ProductOption> */
class OptionResource extends Resource
{
    use ResolvesNumericRecords;

    protected static ?string $model = ProductOption::class;

    protected static ?string $modelLabel = 'opcija';

    protected static ?string $pluralModelLabel = 'Opcije';

    /** @return Builder<ProductOption> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('shared', true);
    }

    /** @return list<Tab> */
    public static function nameTabs(): array
    {
        return array_map(fn (string $locale) => Tab::make(strtoupper($locale))->schema([
            TextInput::make('names.'.$locale)->label('Naziv')
                ->required($locale === config('oneshop.primary_locale'))->maxLength(255),
        ]), ProductEditor::locales());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Prijevodi')->tabs(self::nameTabs())->columnSpanFull(),
            Select::make('display')->label('Preferirani odabir u trgovini')->required()->default('dropdown')
                ->options(['dropdown' => 'Padajući izbornik', 'radio' => 'Radio gumbi'])
                ->helperText('Svaki SKU ima jednu vrijednost po opciji. Višestruki dodaci jednoj stavci uređuju se zasebno.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('translated_name')->label('Naziv')
                ->state(fn (ProductOption $record) => $record->translate('name', config('oneshop.primary_locale'))),
            TextColumn::make('values_count')->counts('values')->label('Vrijednosti'),
            TextColumn::make('products_count')->counts('products')->label('Proizvodi'),
        ])->recordActions([EditAction::make()])->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [ValuesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOptions::route('/'),
            'create' => CreateOption::route('/create'),
            'edit' => EditOption::route('/{record}/edit'),
        ];
    }
}
