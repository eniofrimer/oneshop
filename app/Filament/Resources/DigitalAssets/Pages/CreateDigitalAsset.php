<?php

namespace App\Filament\Resources\DigitalAssets\Pages;

use App\Digital\DigitalAssetEditor;
use App\Filament\Resources\DigitalAssets\DigitalAssetResource;
use App\Filament\Support\CatalogValidation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Lunar\Models\ProductVariant;

class CreateDigitalAsset extends CreateRecord
{
    protected static string $resource = DigitalAssetResource::class;

    public function getTitle(): string
    {
        return 'Dodaj privatnu datoteku';
    }

    protected function handleRecordCreation(array $data): Model
    {
        return CatalogValidation::run($this->form, fn () => app(DigitalAssetEditor::class)->upload(
            ProductVariant::query()->findOrFail($data['product_variant_id']), $data['file'], $data['label']
        ));
    }

    protected function getRedirectUrl(): string
    {
        return DigitalAssetResource::getUrl('index');
    }
}
