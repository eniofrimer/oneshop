<?php

namespace App\Filament\Support;

use Closure;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/** @phpstan-require-extends Resource */
trait ResolvesNumericRecords
{
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        // Reject malformed/overflowing IDs before PostgreSQL attempts to cast
        // the route text to BIGINT. Valid records still use the resource scope.
        if (! ctype_digit((string) $key) || filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return null;
        }

        return parent::resolveRecordRouteBinding($key, $modifyQuery);
    }
}
