<?php

namespace App\Catalog;

use App\Models\DigitalAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;

class ProductPublication
{
    /** @return list<string> */
    public function issues(Product $product): array
    {
        Gate::authorize('view', $product);
        $issues = [];
        $name = CatalogTranslations::formData($product)[config('oneshop.primary_locale')]['name'];
        if (trim($name) === '') {
            $issues[] = 'Nedostaje naziv na osnovnom jeziku trgovine.';
        }
        $variants = ProductVariant::query()->where('product_id', $product->id)->with('values')->get();
        if ($variants->isEmpty()) {
            $issues[] = 'Dodajte barem jedan SKU.';
        }
        $optionIds = ProductOptions::forProduct($product)->pluck('id')->sort()->values()->all();
        if ($optionIds === [] && $variants->count() > 1) {
            $issues[] = 'Proizvod s više SKU-ova treba opcije za razlikovanje varijanti.';
        }
        $currency = Currency::query()->where('code', 'EUR')->where('enabled', true)->where('decimal_places', 2)->first();
        if (! $currency) {
            $issues[] = 'EUR valuta mora biti aktivna i imati dvije decimale.';
        }
        $prices = Price::query()->where('priceable_type', (new ProductVariant)->getMorphClass())
            ->whereIn('priceable_id', $variants->modelKeys())->where('currency_id', $currency?->id)
            ->whereNull('customer_group_id')->where('min_quantity', 1)->get()->groupBy('priceable_id');
        $assets = DigitalAsset::query()->whereIn('product_variant_id', $variants->modelKeys())->whereNull('archived_at')->get()
            ->filter(fn (DigitalAsset $asset) => preg_match('/^digital-assets\/[0-9a-f-]{36}\/payload$/D', $asset->path) && Storage::disk('private')->exists($asset->path))
            ->pluck('product_variant_id')->all();
        $combinations = [];
        foreach ($variants as $variant) {
            $label = 'SKU '.$variant->sku.': ';
            if (trim($variant->sku) === '') {
                $issues[] = 'Varijanti nedostaje SKU oznaka.';
            }
            $basePrices = $prices->get($variant->id);
            if (! $basePrices || $basePrices->count() !== 1 || (int) $basePrices->first()->getRawOriginal('price') < 0) {
                $issues[] = $label.'potrebna je jedna osnovna EUR cijena za jedan komad.';
            }
            if ($variant->unit_quantity !== 1 || $variant->min_quantity !== 1 || $variant->quantity_increment !== 1) {
                $issues[] = $label.'objava pakiranja još nije podržana.';
            }
            if (! $variant->shippable && ! in_array($variant->id, $assets, true)) {
                $issues[] = $label.'dodajte aktivnu privatnu datoteku koja postoji u spremištu.';
            }
            $values = $variant->values;
            $valueOptions = $values->pluck('product_option_id')->sort()->values()->all();
            if ($valueOptions !== $optionIds) {
                $issues[] = $label.'odaberite točno jednu vrijednost za svaku opciju proizvoda.';
            }
            $combination = implode(':', $values->pluck('id')->sort()->all());
            if ($optionIds !== [] && in_array($combination, $combinations, true)) {
                $issues[] = $label.'kombinacija opcija već postoji na drugom SKU-u.';
            }
            $combinations[] = $combination;
        }

        return $issues;
    }

    /** @param array<string, mixed> $data */
    public function publish(Product $product, array $data): void
    {
        Gate::authorize('publish', $product);
        $validated = Validator::make($data, [
            'channel_ids' => ['required', 'array', 'min:1', 'max:100'],
            'channel_ids.*' => ['required', 'integer', 'distinct', Rule::exists(Channel::class, 'id')->whereNull('deleted_at')],
            'customer_group_ids' => ['required', 'array', 'min:1', 'max:100'],
            'customer_group_ids.*' => ['required', 'integer', 'distinct', Rule::exists(CustomerGroup::class, 'id')],
        ])->validate();
        DB::transaction(function () use ($product, $validated): void {
            $record = Product::query()->lockForUpdate()->findOrFail($product->id);
            $this->checkStatus($record);
            $issues = $this->issues($record);
            if ($issues !== []) {
                throw ValidationException::withMessages(['publication' => $issues]);
            }
            $channels = array_map(intval(...), $validated['channel_ids']);
            foreach ($record->channels()->get() as $channel) {
                $record->channels()->updateExistingPivot($channel->getKey(), ['enabled' => false]);
            }
            $record->channels()->syncWithoutDetaching(collect($channels)->mapWithKeys(fn ($id) => [$id => ['enabled' => true, 'starts_at' => now(), 'ends_at' => null]])->all());
            $groups = array_map(intval(...), $validated['customer_group_ids']);
            foreach ($record->customerGroups()->get() as $group) {
                $record->customerGroups()->updateExistingPivot($group->getKey(), ['enabled' => false, 'visible' => false, 'purchasable' => false]);
            }
            $record->customerGroups()->syncWithoutDetaching(collect($groups)->mapWithKeys(fn ($id) => [$id => ['enabled' => true, 'visible' => true, 'purchasable' => true, 'starts_at' => now(), 'ends_at' => null]])->all());
            $record->update(['status' => 'published']);
            activity()->performedOn($record)->causedBy(auth()->user())->event('catalog-published')
                ->withProperties(['channel_ids' => $channels, 'customer_group_ids' => $groups])->log('Catalog publication');
        });
    }

    public function withdraw(Product $product): void
    {
        Gate::authorize('publish', $product);
        DB::transaction(function () use ($product): void {
            $record = Product::query()->lockForUpdate()->findOrFail($product->id);
            $this->checkStatus($record);
            if ($record->status === 'published') {
                $record->update(['status' => 'draft']);
                activity()->performedOn($record)->causedBy(auth()->user())->event('catalog-withdrawn')->log('Catalog withdrawal');
            }
        });
    }

    private function checkStatus(Product $product): void
    {
        if (! in_array($product->status, ['draft', 'published'], true)) {
            throw ValidationException::withMessages(['publication' => 'Ovaj status proizvoda nije podržan za objavu u ovom uređivaču.']);
        }
    }
}
