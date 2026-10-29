<?php

namespace App\Filament\Resources\Categories;

use App\Catalog\CategoryEditor;
use App\Catalog\ProductEditor;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;

/** @extends resource<Collection> */
class CategoryResource extends Resource
{
    protected static ?string $model = Collection::class;

    protected static ?string $modelLabel = 'kategorija';

    protected static ?string $pluralModelLabel = 'Kategorije';

    /** @return Builder<Collection> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', 'static')->with(['group', 'parent']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('collection_group_id')->label('Grupa kategorija')->required()
                ->options(fn () => CollectionGroup::query()->orderBy('name')->pluck('name', 'id'))
                ->disabledOn('edit')->dehydrated()->live()->afterStateUpdated(fn ($set) => $set('parent_id', null))
                ->createOptionForm([
                    TextInput::make('name')->label('Naziv grupe')->required()->maxLength(255),
                    TextInput::make('handle')->label('Oznaka grupe')->required()->maxLength(100)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D')->unique(CollectionGroup::class, 'handle')
                        ->helperText('Jedinstvena oznaka malim slovima, npr. katalog.'),
                ])->createOptionUsing(fn (array $data) => app(CategoryEditor::class)->createGroup($data)->id),
            Select::make('parent_id')->label('Nadređena kategorija')->searchable()
                ->options(fn (Get $get, ?Collection $record) => Collection::query()->where('type', 'static')
                    ->where('collection_group_id', $get('collection_group_id'))->when($record, fn ($query) => $query->whereKeyNot($record->id))
                    ->orderBy('_lft')->get()->mapWithKeys(function (Model $category): array {
                        if (! $category instanceof Collection) {
                            throw new \LogicException('Unsupported category model.');
                        }

                        return [$category->id => $category->translateAttribute('name', config('oneshop.primary_locale'))];
                    }))
                ->helperText('Prazno znači početnu razinu. Kategorije se premještaju unutar iste grupe.'),
            Tabs::make('Prijevodi')->tabs(array_map(fn (string $locale) => Tab::make(strtoupper($locale))->schema([
                TextInput::make('translations.'.$locale.'.name')->label('Naziv')->maxLength(255)
                    ->required($locale === config('oneshop.primary_locale')),
                Textarea::make('translations.'.$locale.'.description')->label('Opis')->rows(5)->maxLength(20000),
            ]), ProductEditor::locales()))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('name')->label('Naziv')
                ->state(fn (Collection $record) => $record->translateAttribute('name', config('oneshop.primary_locale'))),
            TextColumn::make('group.name')->label('Grupa')->sortable(),
            TextColumn::make('parent_name')->label('Nadređena kategorija')->placeholder('Početna razina')
                ->state(function (Collection $record) {
                    $parent = $record->getRelationValue('parent');

                    return $parent instanceof Collection ? $parent->translateAttribute('name', config('oneshop.primary_locale')) : null;
                }),
            TextColumn::make('products_count')->label('Proizvodi')->counts('products'),
        ])->filters([
            SelectFilter::make('collection_group_id')->label('Grupa')->options(fn () => CollectionGroup::query()->pluck('name', 'id')),
        ])->recordActions([EditAction::make()])->defaultSort('_lft');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
