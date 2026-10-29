<?php

namespace App\Filament\Resources\DigitalAssets\Pages;

use App\Filament\Resources\DigitalAssets\DigitalAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDigitalAssets extends ListRecords
{
    protected static string $resource = DigitalAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
