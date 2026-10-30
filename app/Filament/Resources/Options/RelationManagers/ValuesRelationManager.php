<?php

namespace App\Filament\Resources\Options\RelationManagers;

use App\Catalog\OptionEditor;
use App\Filament\Resources\Options\OptionResource;
use App\Filament\Support\CatalogValidation;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductOptionValue;

class ValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'values';

    protected static ?string $title = 'Vrijednosti opcije';

    private function option(): ProductOption
    {
        $option = $this->getOwnerRecord();
        abort_unless($option instanceof ProductOption, 404);

        return $option;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Prijevodi')->tabs(OptionResource::nameTabs())->columnSpanFull(),
            TextInput::make('position')->label('Redoslijed')->integer()->minValue(0)->maxValue(32767)->default(0)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->modelLabel('vrijednost opcije')->pluralModelLabel('vrijednosti opcije')->columns([
            TextColumn::make('translated_name')->label('Naziv')
                ->state(fn (ProductOptionValue $record) => $record->translate('name', config('oneshop.primary_locale'))),
            TextColumn::make('position')->label('Redoslijed')->sortable(),
        ])->defaultSort('position')->headerActions([
            CreateAction::make()->label('Dodaj vrijednost')
                ->using(fn (array $data) => CatalogValidation::run($this->getMountedActionSchema(), fn () => app(OptionEditor::class)->saveValue($this->option(), $data))),
        ])->recordActions([
            EditAction::make()->mutateRecordDataUsing(fn (ProductOptionValue $record) => OptionEditor::formData($record))
                ->using(fn (ProductOptionValue $record, array $data) => CatalogValidation::run($this->getMountedActionSchema(), fn () => app(OptionEditor::class)->saveValue($this->option(), $data, $record))),
        ]);
    }
}
