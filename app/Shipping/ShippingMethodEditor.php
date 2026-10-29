<?php

namespace App\Shipping;

use App\Models\ShippingMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Country;
use Lunar\Models\TaxClass;

class ShippingMethodEditor
{
    /** @return array<string, mixed> */
    public static function formData(ShippingMethod $method): array
    {
        return ['name' => $method->name, 'description' => $method->description, 'countries' => $method->countries,
            'tax_class_id' => $method->tax_class_id, 'enabled' => $method->enabled, 'version' => $method->version,
            'price_eur' => intdiv($method->price_minor, 100).'.'.str_pad((string) ($method->price_minor % 100), 2, '0', STR_PAD_LEFT)];
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?ShippingMethod $method = null): ShippingMethod
    {
        Gate::authorize($method ? 'update' : 'create', $method ?? ShippingMethod::class);
        $data['price_eur'] = is_string($data['price_eur'] ?? null) ? trim($data['price_eur']) : $data['price_eur'] ?? null;
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'],
            'countries' => ['required', 'array', 'min:1', 'max:250'],
            'countries.*' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/D', 'distinct', Rule::exists(Country::class, 'iso2')],
            'price_eur' => ['required', 'regex:/^\d{1,8}(?:[.,]\d{1,2})?$/D'],
            'tax_class_id' => ['required', 'integer', Rule::exists(TaxClass::class, 'id')],
            'enabled' => ['required', 'boolean'], 'version' => [$method ? 'required' : 'nullable', 'integer', 'min:1'],
        ])->validate();
        $parts = explode('.', str_replace(',', '.', (string) $validated['price_eur']));
        $minor = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        if ($minor > 2147483647) {
            throw ValidationException::withMessages(['price_eur' => 'Cijena prelazi podržani raspon.']);
        }

        return DB::transaction(function () use ($method, $validated, $minor): ShippingMethod {
            $record = $method ? ShippingMethod::query()->lockForUpdate()->findOrFail($method->id) : new ShippingMethod;
            if ($method && $record->version !== (int) $validated['version']) {
                throw ValidationException::withMessages(['name' => 'Dostava je u međuvremenu promijenjena. Ponovno otvorite zapis.']);
            }
            $record->forceFill(['name' => trim($validated['name']), 'description' => $validated['description'] ?? null,
                'countries' => array_values($validated['countries']), 'price_minor' => $minor,
                'tax_class_id' => $validated['tax_class_id'], 'enabled' => $validated['enabled'],
                'version' => $method ? $record->version + 1 : 1]);
            if (! $method) {
                $record->forceFill(['code' => (string) Str::uuid()]);
            }
            $record->save();

            return $record;
        });
    }
}
