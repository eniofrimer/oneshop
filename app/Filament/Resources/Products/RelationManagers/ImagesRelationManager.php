<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Catalog\ProductEditor;
use App\Catalog\ProductImages;
use App\Filament\Support\CatalogValidation;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Fotografije';

    private function product(): Product
    {
        $product = $this->getOwnerRecord();
        abort_unless($product instanceof Product, 404);

        return $product;
    }

    protected function canReorder(): bool
    {
        return Gate::allows('update', $this->product());
    }

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        try {
            app(ProductImages::class)->reorder($this->product(), $order);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Redoslijed nije spremljen')
                ->body(collect($exception->errors())->flatten()->first())->send();
        }
        $this->flushCachedTableRecords();
    }

    /** @return array<TextInput|Tabs> */
    private static function metadataFields(): array
    {
        return [
            TextInput::make('title')->label('Naziv fotografije')->required()->maxLength(255),
            Tabs::make('Opis fotografije')->tabs(array_map(fn (string $locale) => Tab::make(strtoupper($locale))->schema([
                TextInput::make('alt.'.$locale)->label('Alternativni tekst')->maxLength(255)
                    ->helperText('Kratak opis sadržaja fotografije za pristupačan prikaz.'),
            ]), ProductEditor::locales())),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel('fotografija')->pluralModelLabel('fotografije')
            ->modifyQueryUsing(fn ($query) => $query->where('collection_name', config('lunar.media.collection'))->where('disk', 'public'))
            ->recordTitleAttribute('name')->defaultSort('order_column')->reorderable('order_column')
            ->description('Javne fotografije proizvoda, dostupne i dok je proizvod nacrt. Prva fotografija je naslovna. JPEG, PNG ili WebP, do 5 MB i 4096 × 4096 px; najviše 10 fotografija.')
            ->columns([
                ImageColumn::make('preview')->label('Fotografija')
                    ->state(fn (Media $record) => $record->hasGeneratedConversion('small') ? $record->getUrl('small') : $record->getUrl()),
                TextColumn::make('name')->label('Naziv'),
                IconColumn::make('custom_properties.primary')->label('Naslovna')->boolean(),
            ])
            ->headerActions([
                Action::make('upload')->label('Dodaj fotografiju')
                    ->authorize(fn () => Gate::allows('update', $this->product()))
                    ->schema([
                        FileUpload::make('image')->label('Fotografija')->required()->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(ProductImages::MAX_KILOBYTES)->storeFiles(false),
                        ...self::metadataFields(),
                    ])
                    ->action(fn (array $data) => CatalogValidation::run($this->getMountedActionSchema(), fn () => app(ProductImages::class)->add($this->product(), $data['image'], $data))),
            ])
            ->recordActions([
                EditAction::make()->authorize(fn () => Gate::allows('update', $this->product()))
                    ->fillForm(fn (Media $record) => ProductImages::formData($record))
                    ->schema(self::metadataFields())
                    ->using(fn (Media $record, array $data) => CatalogValidation::run($this->getMountedActionSchema(), fn () => app(ProductImages::class)->update($this->product(), $record, $data))),
                DeleteAction::make()->authorize(fn () => Gate::allows('update', $this->product()))
                    ->using(function (Media $record): bool {
                        app(ProductImages::class)->remove($this->product(), $record);

                        return true;
                    }),
            ]);
    }
}
