<?php

namespace App\Filament\Resources\Products\Pages;

use App\Catalog\ProductEditor;
use App\Catalog\ProductOptions;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\CatalogValidation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Lunar\Models\Collection;
use Lunar\Models\Product;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

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
