<?php

namespace App\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lunar\FieldTypes\TranslatedText;

class CatalogTranslations
{
    /** @return array<string, array<string, string>> */
    public static function formData(Model $record): array
    {
        $attributes = $record->getAttribute('attribute_data');
        $translations = [];
        foreach (ProductEditor::locales() as $locale) {
            foreach (['name', 'description'] as $field) {
                $value = $attributes instanceof Collection ? $attributes->get($field) : null;
                $translations[$locale][$field] = $value instanceof TranslatedText
                    ? (string) ($value->getValue()->get($locale)?->getValue() ?? '') : '';
            }
        }

        return $translations;
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $locales = ProductEditor::locales();
        $rules = ['translations' => ['required', 'array:'.implode(',', $locales)]];
        foreach ($locales as $locale) {
            $rules["translations.$locale"] = ['required', 'array:name,description'];
            $rules["translations.$locale.name"] = [$locale === config('oneshop.primary_locale') ? 'required' : 'nullable', 'string', 'max:255'];
            $rules["translations.$locale.description"] = ['nullable', 'string', 'max:20000'];
        }

        return $rules;
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @return Collection<array-key, mixed>
     */
    public static function merge(Model $record, array $translations): Collection
    {
        $attributes = $record->getAttribute('attribute_data');
        $attributes = $attributes instanceof Collection ? $attributes : collect();
        foreach (['name', 'description'] as $field) {
            $existing = $attributes->get($field);
            $values = $existing instanceof TranslatedText ? $existing->getValue()->all() : [];
            foreach (ProductEditor::locales() as $locale) {
                $values[$locale] = trim($translations[$locale][$field] ?? '');
            }
            $attributes->put($field, new TranslatedText(collect($values)));
        }

        return $attributes;
    }
}
