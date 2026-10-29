<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Catalog\CatalogTranslations;
use App\Catalog\CategoryEditor;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Support\CatalogValidation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Lunar\Models\Collection;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Collection, 404);

        return ['collection_group_id' => $record->collection_group_id, 'parent_id' => $record->parent_id, 'translations' => CatalogTranslations::formData($record)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Collection, 404);

        return CatalogValidation::run($this->form, fn () => app(CategoryEditor::class)->save($data, $record));
    }
}
