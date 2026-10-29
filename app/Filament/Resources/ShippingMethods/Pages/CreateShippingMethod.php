<?php

namespace App\Filament\Resources\ShippingMethods\Pages;

use App\Filament\Resources\ShippingMethods\ShippingMethodResource;
use App\Filament\Support\CatalogValidation;
use App\Shipping\ShippingMethodEditor;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateShippingMethod extends CreateRecord
{
    protected static string $resource = ShippingMethodResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return CatalogValidation::run($this->form, fn () => app(ShippingMethodEditor::class)->save($data));
    }

    protected function getRedirectUrl(): string
    {
        return ShippingMethodResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
