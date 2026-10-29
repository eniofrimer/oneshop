<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Lunar\Models\Order;

abstract class OrderRelationManager extends RelationManager
{
    #[Unexposed]
    public function booted(): void
    {
        abort_unless(self::canViewForRecord($this->getOwnerRecord(), static::class), 403);
    }

    #[Unexposed]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Order && $ownerRecord->isPlaced() && Gate::allows('view', $ownerRecord);
    }

    #[Unexposed]
    public function isReadOnly(): bool
    {
        return true;
    }
}
