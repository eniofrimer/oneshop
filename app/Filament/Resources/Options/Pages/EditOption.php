<?php

namespace App\Filament\Resources\Options\Pages;

use App\Catalog\OptionEditor;
use App\Filament\Resources\Options\OptionResource;
use App\Filament\Support\CatalogValidation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Lunar\Models\ProductOption;

class EditOption extends EditRecord
{
    protected static string $resource = OptionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        abort_unless($record instanceof ProductOption, 404);

        return OptionEditor::formData($record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof ProductOption, 404);

        return CatalogValidation::run($this->form, fn () => app(OptionEditor::class)->saveOption($data, $record));
    }
}
