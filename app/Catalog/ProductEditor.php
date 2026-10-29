<?php

namespace App\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Collection;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductType;

class ProductEditor
{
    /** @return list<string> */
    public static function locales(): array
    {
        $locales = config('oneshop.enabled_locales');
        $primary = config('oneshop.primary_locale');

        if (! is_array($locales) || ! array_is_list($locales) || ! in_array($primary, $locales, true)) {
            throw ValidationException::withMessages(['translations' => 'Najprije konfigurirajte jezike trgovine.']);
        }

        foreach ($locales as $locale) {
            if (! is_string($locale) || ! preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $locale)) {
                throw ValidationException::withMessages(['translations' => 'Neispravna oznaka jezika trgovine.']);
            }
        }

        return $locales;
    }

    /** @return array<string, array<string, string>> */
    public static function translations(Product $product): array
    {
        return CatalogTranslations::formData($product);
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?Product $product = null): Product
    {
        Gate::authorize($product ? 'update' : 'create', $product ?? Product::class);
        $rules = [
            'product_type_id' => ['required', 'integer', Rule::exists(ProductType::class, 'id')],
            ...CatalogTranslations::rules(),
            'collection_ids' => ['sometimes', 'array', 'max:100'],
            'collection_ids.*' => ['required', 'integer', 'distinct', Rule::exists(Collection::class, 'id')->where('type', 'static')->whereNull('deleted_at')],
            'option_ids' => ['sometimes', 'array', 'max:10'],
            'option_ids.*' => ['required', 'integer', 'distinct', Rule::exists(ProductOption::class, 'id')->where('shared', true)],
        ];

        $validated = Validator::make($data, $rules)->validate();

        return DB::transaction(function () use ($validated, $product): Product {
            $record = $product ? Product::query()->lockForUpdate()->findOrFail($product->id) : new Product;
            $record->fill([
                'product_type_id' => $validated['product_type_id'],
                'attribute_data' => CatalogTranslations::merge($record, $validated['translations']),
            ]);
            if (! $record->exists) {
                $record->status = 'draft';
            }
            $record->save();
            if (array_key_exists('option_ids', $validated)) {
                $requested = array_map(intval(...), $validated['option_ids']);
                $current = $record->productOptions()->get();
                $removed = $current->where('shared', true)->pluck('id')->diff($requested);
                if ($removed->isNotEmpty() && $record->variants()->whereHas('values', fn ($query) => $query->whereIn('product_option_id', $removed))->exists()) {
                    throw ValidationException::withMessages(['option_ids' => 'Opciju koriste aktivni SKU-ovi. Najprije arhivirajte te varijante dok je proizvod nacrt.']);
                }
                $added = array_diff($requested, $current->pluck('id')->all());
                if ($record->status === 'published' && $added !== [] && $record->variants()->exists()) {
                    throw ValidationException::withMessages(['option_ids' => 'Prije dodavanja novih opcija pripremite proizvod kao nacrt.']);
                }
                // Exclusive options owned by imported integrations are outside this editor.
                $ids = [...$current->where('shared', false)->pluck('id')->all(), ...$requested];
                $record->productOptions()->sync(collect($ids)->mapWithKeys(fn ($id, $position) => [$id => ['position' => $position]])->all());
            }

            if (array_key_exists('collection_ids', $validated)) {
                $manualIds = $record->collections()->where('type', 'static')->pluck((new Collection)->getQualifiedKeyName());
                $record->collections()->detach($manualIds->diff($validated['collection_ids'])->all());
                $record->collections()->syncWithoutDetaching($validated['collection_ids']);
            }

            return $record;
        });
    }
}
