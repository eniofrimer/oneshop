<?php

namespace App\Filament\Support;

use Closure;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @phpstan-require-extends Resource */
trait ResolvesUuidRecords
{
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        if (! is_string($key) || ! Str::isUuid($key)) {
            return null;
        }

        return parent::resolveRecordRouteBinding($key, $modifyQuery);
    }
}
