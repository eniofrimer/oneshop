<?php

namespace App\Filament\Resources\Products\Pages;

use App\Catalog\ProductEditor;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\CatalogValidation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return CatalogValidation::run($this->form, fn () => app(ProductEditor::class)->save($data));
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
