<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\TaxClass;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ShippingMethod extends Model
{
    use LogsActivity;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['countries' => 'array', 'price_minor' => 'integer', 'enabled' => 'boolean', 'version' => 'integer'];
    }

    /** @return BelongsTo<TaxClass, $this> */
    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('shipping')
            ->logOnly(['name', 'description', 'countries', 'price_minor', 'tax_class_id', 'enabled'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
