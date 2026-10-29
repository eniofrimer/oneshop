<?php

namespace Tests\Feature;

use App\Storefront\StoreTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Models\Cart;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class StorefrontThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepared_themes_share_the_same_catalogue_cart_and_checkout(): void
    {
        config(['oneshop.demo_checkout' => true]);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 2])->assertRedirect();
        $this->post(route('store.checkout.address'), ['first_name' => 'Tema', 'last_name' => 'Test',
            'contact_email' => 'theme@example.test', 'contact_phone' => '+385910000000',
            'line_one' => 'Testna ulica 1', 'postcode' => '10000', 'city' => 'Zagreb',
            'country_id' => Country::query()->where('iso2', 'HR')->sole()->id])->assertRedirect();
        $cartId = Cart::query()->sole()->id;

        foreach (StoreTheme::cases() as $theme) {
            config(['oneshop.theme' => $theme->value]);
            foreach (['store.home', 'store.cart', 'store.checkout', 'store.checkout.review'] as $route) {
                $this->get(route($route))->assertOk()->assertSee('theme-'.$theme->value, false);
            }
            $this->get(route('store.product', $variant->product))->assertOk()
                ->assertSee('theme-'.$theme->value, false)->assertSee('name="variant_id"', false);
            $this->get(route('store.checkout.review'))->assertOk()->assertSee('35,00 €');
            $this->assertSame($cartId, Cart::query()->sole()->id);
            $this->assertSame(2, Cart::query()->sole()->lines()->sole()->quantity);
            $this->assertSame(20, $variant->fresh()->stock);
            $this->assertSame(0, Order::query()->count());
        }
    }

    public function test_unknown_or_malformed_theme_configuration_uses_the_reference_layout(): void
    {
        foreach (['unknown', 'studio" onload="alert(1)', ['studio'], null] as $theme) {
            config(['oneshop.theme' => $theme]);
            $this->get('/')->assertOk()->assertSee('class="shop theme-everyday"', false)
                ->assertDontSee('onload="alert(1)', false);
        }
    }
}
