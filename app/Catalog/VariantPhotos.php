<?php

namespace App\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class VariantPhotos
{
    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return Builder<Media> */
    public static function eligible(Product $product): Builder
    {
        return ProductImages::query($product)->whereIn('mime_type', self::MIME_TYPES);
    }

    /**
     * @param  BelongsToMany<Media, ProductVariant>  $images
     * @return BelongsToMany<Media, ProductVariant>
     */
    public static function constrain(BelongsToMany $images): BelongsToMany
    {
        return $images->wherePivot('primary', true)
            ->where('disk', 'public')->where('collection_name', config('lunar.media.collection'))
            ->where('model_type', (new Product)->getMorphClass())->whereIn('mime_type', self::MIME_TYPES)
            ->orderBy((new Media)->getQualifiedKeyName());
    }

    public static function current(ProductVariant $variant): ?Media
    {
        if (! $variant->relationLoaded('images')) {
            $variant->setRelation('images', self::constrain($variant->images())->get());
        }
        foreach ($variant->getRelation('images') as $image) {
            $pivot = $image instanceof Media ? $image->getRelationValue('pivot') : null;
            if ($image instanceof Media && $pivot instanceof Pivot && (bool) $pivot->getAttribute('primary')
                && $image->disk === 'public' && $image->collection_name === config('lunar.media.collection')
                && $image->model_type === (new Product)->getMorphClass()
                && (int) $image->model_id === (int) $variant->product_id && in_array($image->mime_type, self::MIME_TYPES, true)) {
                return $image;
            }
        }

        return null;
    }

    public function assign(Product $product, ProductVariant $variant, mixed $imageId): void
    {
        Gate::authorize('update', $product);
        $data = Validator::make(['image_id' => $imageId === '' ? null : $imageId], [
            'image_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $id = $data['image_id'] === null ? null : (int) $data['image_id'];

        DB::transaction(function () use ($product, $variant, $id): void {
            $parent = Product::query()->lockForUpdate()->findOrFail($product->id);
            $record = ProductVariant::query()->where('product_id', $parent->id)->lockForUpdate()->findOrFail($variant->id);
            if ($id !== null && ! self::eligible($parent)->whereKey($id)->exists()) {
                throw ValidationException::withMessages(['image_id' => 'Odaberite javnu fotografiju ovog proizvoda.']);
            }
            $before = $record->images()->wherePivot('primary', true)->orderBy((new Media)->getQualifiedKeyName())
                ->pluck((new Media)->getQualifiedKeyName())->map(intval(...))->unique()->values()->all();
            $after = $id === null ? [] : [$id];
            if ($before === $after) {
                return;
            }
            $record->images()->wherePivot('primary', true)->newPivotQuery()->update(['primary' => false, 'updated_at' => now()]);
            if ($id !== null) {
                if ($record->images()->whereKey($id)->exists()) {
                    $record->images()->updateExistingPivot($id, ['primary' => true]);
                } else {
                    $record->images()->attach($id, ['primary' => true, 'position' => 0]);
                }
            }
            activity('catalog')->performedOn($record)->causedBy(auth()->user())->event('sku-photo-updated')
                ->withProperties(['product_id' => $parent->id, 'before_media_ids' => $before, 'after_media_ids' => $after])
                ->log('SKU primary photo changed');
        });
        $variant->unsetRelation('images');
    }
}
