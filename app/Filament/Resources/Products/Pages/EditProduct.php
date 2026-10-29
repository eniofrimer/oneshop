<?php

namespace App\Filament\Resources\Products\Pages;

use App\Catalog\ProductEditor;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\CatalogValidation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
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
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Product, 404);

        return CatalogValidation::run($this->form, fn () => app(ProductEditor::class)->save($data, $record));
    }
}
