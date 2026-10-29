<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Catalog\CategoryEditor;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Support\CatalogValidation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return CatalogValidation::run($this->form, fn () => app(CategoryEditor::class)->save($data));
    }

    protected function getRedirectUrl(): string
    {
        return CategoryResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
