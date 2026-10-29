<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\ProductVariant;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class DigitalAsset extends Model
{
    use LogsActivity;

    protected $guarded = ['*'];

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'archived_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $asset): void {
            if ($asset->isDirty(['id', 'created_at', 'product_variant_id', 'label', 'original_name', 'path', 'mime_type', 'size', 'sha256'])) {
                throw new \LogicException('Stored digital assets are immutable; upload a new asset instead.');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('digital-assets')
            ->logOnly(['label', 'product_variant_id', 'archived_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
