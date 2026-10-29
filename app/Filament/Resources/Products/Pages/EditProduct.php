<?php

namespace App\Filament\Resources\Products\Pages;

use App\Catalog\ProductEditor;
use App\Catalog\ProductOptions;
use App\Catalog\ProductPublication;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\CatalogValidation;
use App\Storefront\StoreCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Channel;
use Lunar\Models\Collection;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Product;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    private function product(): Product
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Product, 404);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openStorefront')->label('Otvori u trgovini')
                ->url(fn () => route('store.product', $this->product()->id))->openUrlInNewTab()
                ->visible(fn () => app(StoreCatalog::class)->products()->whereKey($this->product()->id)->exists()),
            Action::make('publish')->label('Objava u katalogu')
                ->modalDescription('Objavljuju se spremljeni podaci. Najprije spremite izmjene obrasca proizvoda.')
                ->authorize(fn () => Gate::allows('publish', $this->product()))
                ->fillForm(fn () => [
                    'channel_ids' => $this->product()->channels()->wherePivot('enabled', true)->pluck((new Channel)->getQualifiedKeyName())->all(),
                    'customer_group_ids' => $this->product()->customerGroups()->wherePivot('enabled', true)->wherePivot('visible', true)->pluck((new CustomerGroup)->getQualifiedKeyName())->all(),
                ])
                ->schema([
                    TextEntry::make('readiness')->label('Provjera kataloga')
                        ->state(fn () => app(ProductPublication::class)->issues($this->product()) ?: ['Podaci su spremni za objavu u katalogu.'])->listWithLineBreaks(),
                    Select::make('channel_ids')->label('Kanali')->multiple()->required()->minItems(1)->maxItems(100)
                        ->options(fn () => Channel::query()->orderBy('name')->pluck('name', 'id')),
                    Select::make('customer_group_ids')->label('Grupe kupaca')->multiple()->required()->minItems(1)->maxItems(100)
                        ->options(fn () => CustomerGroup::query()->orderBy('name')->pluck('name', 'id'))
                        ->helperText('Odabrani kanali i grupe bit će dostupni odmah. Ostale postojeće veze bit će isključene.'),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(ProductPublication::class)->publish($this->product(), $data);
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title('Objava nije spremljena')
                            ->body(e(collect($exception->errors())->flatten()->implode(' ')))->persistent()->send();
                        $action->halt();
                    }
                    $this->getRecord()->refresh();
                    Notification::make()->success()->title('Proizvod je objavljen u katalogu')->send();
                }),
            Action::make('withdraw')->label('Vrati u nacrt')->requiresConfirmation()
                ->visible(fn () => $this->product()->status === 'published')
                ->authorize(fn () => Gate::allows('publish', $this->product()))
                ->action(function (): void {
                    app(ProductPublication::class)->withdraw($this->product());
                    $this->getRecord()->refresh();
                    Notification::make()->success()->title('Proizvod je vraćen u nacrt')->send();
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $product */
        $product = $this->getRecord();

        return [
            'product_type_id' => $product->product_type_id,
            'translations' => ProductEditor::translations($product),
            'collection_ids' => $product->collections()->where('type', 'static')->pluck((new Collection)->getQualifiedKeyName())->all(),
            'option_ids' => ProductOptions::forProduct($product)->where('shared', true)->pluck('id')->all(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Product, 404);

        return CatalogValidation::run($this->form, fn () => app(ProductEditor::class)->save($data, $record));
    }
}
