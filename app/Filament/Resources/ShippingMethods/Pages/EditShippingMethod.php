<?php

namespace App\Filament\Resources\ShippingMethods\Pages;

use App\Filament\Resources\ShippingMethods\ShippingMethodResource;
use App\Filament\Support\CatalogValidation;
use App\Models\ShippingMethod;
use App\Shipping\ShippingMethodEditor;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditShippingMethod extends EditRecord
{
    protected static string $resource = ShippingMethodResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        abort_unless($record instanceof ShippingMethod, 404);

        return ShippingMethodEditor::formData($record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof ShippingMethod, 404);

        return CatalogValidation::run($this->form, fn () => app(ShippingMethodEditor::class)->save($data, $record));
    }
}
