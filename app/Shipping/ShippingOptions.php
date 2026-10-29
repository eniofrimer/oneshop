<?php

namespace App\Shipping;

use App\Models\ShippingMethod;
use Lunar\DataTypes\Price;
use Lunar\DataTypes\ShippingOption;
use Lunar\Models\Cart;
use Lunar\Models\CartAddress;
use Lunar\Models\Country;
use Lunar\Models\Currency;
use Lunar\Models\TaxClass;

class ShippingOptions
{
    public const PREFIX = 'oneshop-shipping:';

    /** @return list<ShippingOption> */
    public function forCart(Cart $cart): array
    {
        if (! $cart->isShippable()) {
            return [];
        }
        $currency = $cart->currency;
        $address = $cart->shippingAddress;
        $country = $address instanceof CartAddress ? $address->country : null;
        if (! $currency instanceof Currency || $currency->code !== 'EUR' || $currency->decimal_places !== 2 || ! $country instanceof Country) {
            return [];
        }
        $options = [];
        foreach (ShippingMethod::query()->where('enabled', true)->with('taxClass')->orderBy('price_minor')->orderBy('id')->get() as $method) {
            $taxClass = $method->taxClass;
            $countries = $method->getAttribute('countries');
            if (! $taxClass instanceof TaxClass || ! is_array($countries) || ! in_array($country->iso2, $countries, true)) {
                continue;
            }
            $options[] = new ShippingOption(name: $method->name, description: $method->description,
                identifier: self::PREFIX.$method->code, price: new Price($method->price_minor, $currency, 1), taxClass: $taxClass);
        }

        return $options;
    }
}
