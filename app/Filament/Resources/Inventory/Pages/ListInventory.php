<?php

namespace App\Filament\Resources\Inventory\Pages;

use App\Filament\Resources\Inventory\InventoryResource;
use Filament\Resources\Pages\ListRecords;

class ListInventory extends ListRecords
{
    protected static string $resource = InventoryResource::class;
}
