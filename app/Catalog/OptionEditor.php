<?php

namespace App\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductOptionValue;

class OptionEditor
{
    /** @return array<string, mixed> */
    public static function formData(ProductOption|ProductOptionValue $record): array
    {
        $names = self::attributeMap($record, 'name');
        $data = ['names' => []];
        foreach (ProductEditor::locales() as $locale) {
            $data['names'][$locale] = (string) ($names[$locale] ?? '');
        }
        if ($record instanceof ProductOption) {
            $data['display'] = data_get($record->meta, 'oneshop.display', 'dropdown');
        } else {
            $data['position'] = $record->position;
        }

        return $data;
    }

    /** @return array<array-key, mixed> */
    private static function attributeMap(ProductOption|ProductOptionValue $record, string $attribute): array
    {
        $value = $record->getAttribute($attribute);
        if ($value instanceof \ArrayObject) {
            return $value->getArrayCopy();
        }
        if (is_array($value) || $value === null) {
            return $value ?? [];
        }

        throw new \LogicException('Expected an array attribute: '.$attribute);
    }

    /** @return array<string, mixed> */
    private function nameRules(): array
    {
        $locales = ProductEditor::locales();
        $rules = ['names' => ['required', 'array:'.implode(',', $locales)]];
        foreach ($locales as $locale) {
            $rules['names.'.$locale] = [$locale === config('oneshop.primary_locale') ? 'required' : 'nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $names
     * @return array<array-key, mixed>
     */
    private function mergeNames(ProductOption|ProductOptionValue $record, array $names): array
    {
        $existing = self::attributeMap($record, 'name');
        foreach (ProductEditor::locales() as $locale) {
            $existing[$locale] = trim($names[$locale] ?? '');
        }

        return $existing;
    }

    /** @param array<string, mixed> $data */
    public function saveOption(array $data, ?ProductOption $option = null): ProductOption
    {
        Gate::authorize($option ? 'update' : 'create', $option ?? ProductOption::class);
        $validated = Validator::make($data, [
            ...$this->nameRules(),
            'display' => ['required', Rule::in(['dropdown', 'radio'])],
        ])->validate();

        return DB::transaction(function () use ($validated, $option): ProductOption {
            $record = $option ? ProductOption::query()->shared()->lockForUpdate()->findOrFail($option->id) : new ProductOption;
            $meta = self::attributeMap($record, 'meta');
            data_set($meta, 'oneshop.display', $validated['display']);
            $record->fill(['name' => $this->mergeNames($record, $validated['names']), 'meta' => $meta]);
            if (! $record->exists) {
                $record->shared = true;
            }
            $record->save();

            return $record;
        });
    }

    /** @param array<string, mixed> $data */
    public function saveValue(ProductOption $option, array $data, ?ProductOptionValue $value = null): ProductOptionValue
    {
        Gate::authorize('update', $option);
        $validated = Validator::make($data, [
            ...$this->nameRules(),
            'position' => ['required', 'integer', 'min:0', 'max:32767'],
        ])->validate();

        return DB::transaction(function () use ($option, $value, $validated): ProductOptionValue {
            $parent = ProductOption::query()->shared()->lockForUpdate()->findOrFail($option->id);
            /** @var ProductOptionValue $record */
            $record = $value ? $parent->values()->lockForUpdate()->findOrFail($value->id) : new ProductOptionValue;
            $record->fill([
                'product_option_id' => $parent->id,
                'name' => $this->mergeNames($record, $validated['names']),
                'position' => $validated['position'],
            ])->save();

            return $record;
        });
    }
}
