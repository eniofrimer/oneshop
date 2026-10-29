<?php

namespace App\Filament\Resources\Options\Pages;

use App\Catalog\OptionEditor;
use App\Filament\Resources\Options\OptionResource;
use App\Filament\Support\CatalogValidation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOption extends CreateRecord
{
    protected static string $resource = OptionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return CatalogValidation::run($this->form, fn () => app(OptionEditor::class)->saveOption($data));
    }

    protected function getRedirectUrl(): string
    {
        return OptionResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
