<?php

namespace App\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Product;
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
        $translations = [];

        foreach (self::locales() as $locale) {
            foreach (['name', 'description'] as $field) {
                $value = $product->attribute_data?->get($field);
                // Read the exact locale, without Lunar's display fallback.
                $translations[$locale][$field] = $value instanceof TranslatedText
                    ? (string) ($value->getValue()->get($locale)?->getValue() ?? '')
                    : '';
            }
        }

        return $translations;
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?Product $product = null): Product
    {
        Gate::authorize($product ? 'update' : 'create', $product ?? Product::class);
        $locales = self::locales();
        $rules = [
            'product_type_id' => ['required', 'integer', Rule::exists(ProductType::class, 'id')],
            'translations' => ['required', 'array:'.implode(',', $locales)],
        ];

        foreach ($locales as $locale) {
            $rules["translations.$locale"] = ['required', 'array:name,description'];
            $rules["translations.$locale.name"] = [$locale === config('oneshop.primary_locale') ? 'required' : 'nullable', 'string', 'max:255'];
            $rules["translations.$locale.description"] = ['nullable', 'string', 'max:20000'];
        }

        $validated = Validator::make($data, $rules)->validate();

        return DB::transaction(function () use ($validated, $product, $locales): Product {
            $record = $product ? Product::query()->lockForUpdate()->findOrFail($product->id) : new Product;
            $attributes = $record->attribute_data ?? collect();

            foreach (['name', 'description'] as $field) {
                $existing = $attributes->get($field);
                $values = $existing instanceof TranslatedText ? $existing->getValue()->all() : [];
                foreach ($locales as $locale) {
                    $values[$locale] = trim($validated['translations'][$locale][$field] ?? '');
                }
                $attributes->put($field, new TranslatedText(collect($values)));
            }

            $record->fill([
                'product_type_id' => $validated['product_type_id'],
                'attribute_data' => $attributes,
            ]);
            if (! $record->exists) {
                $record->status = 'draft';
            }
            $record->save();

            return $record;
        });
    }
}
