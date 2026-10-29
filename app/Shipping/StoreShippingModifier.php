<?php

namespace App\Shipping;

use Closure;
use Lunar\Base\ShippingManifest;
use Lunar\DataTypes\ShippingOption;
use Lunar\Models\Contracts\Cart;

class StoreShippingModifier
{
    public function handle(Cart $cart, Closure $next): mixed
    {
        if (! $cart instanceof \Lunar\Models\Cart) {
            throw new \LogicException('Unsupported cart model.');
        }
        $manifest = \Lunar\Facades\ShippingManifest::getFacadeRoot();
        if (! $manifest instanceof ShippingManifest) {
            throw new \LogicException('Unsupported shipping manifest.');
        }
        // Lunar keeps its manifest for the request. Remove our previous cart's
        // options before resolving another cart or a changed address/method.
        $manifest->options = $manifest->options->reject(fn (ShippingOption $option) => str_starts_with($option->identifier, ShippingOptions::PREFIX))->values();
        foreach (app(ShippingOptions::class)->forCart($cart) as $option) {
            $manifest->addOption($option);
        }

        return $next($cart);
    }
}
