<?php

namespace Tests\Feature;

use App\Console\Commands\SeedDemoShipping;
use App\Filament\Resources\ShippingMethods\Pages\CreateShippingMethod;
use App\Filament\Resources\ShippingMethods\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethods\ShippingMethodResource;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Shipping\ShippingMethodEditor;
use App\Shipping\ShippingOptions;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\Facades\ShippingManifest;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Country;
use Lunar\Models\Currency;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Tests\TestCase;

class ShippingMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US', 'lunar.pricing.stored_inclusive_of_tax' => false]);
        app()->setLocale('hr');
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin);
    }

    private function data(): array
    {
        return ['name' => 'Testna dostava', 'description' => 'Dostava na adresu', 'countries' => ['HR'], 'tax_class_id' => TaxClass::query()->sole()->id, 'enabled' => true, 'price_eur' => '4,00'];
    }

    private function cart(): Cart
    {
        $cart = Cart::query()->create(['currency_id' => Currency::query()->sole()->id, 'channel_id' => Channel::query()->sole()->id]);
        $cart->add(ProductVariant::query()->where('sku', 'DEMO-CUP')->sole(), 2);
        foreach (['billing', 'shipping'] as $type) {
            $cart->addAddress(['country_id' => Country::query()->where('iso2', 'HR')->sole()->id, 'first_name' => 'Ana', 'last_name' => 'Kupac', 'line_one' => 'Testna 1', 'city' => 'Zagreb', 'postcode' => '10000'], $type);
        }

        return $cart->fresh();
    }

    public function test_admin_creates_and_updates_methods_in_filament_with_integer_prices_and_audit(): void
    {
        Livewire::test(CreateShippingMethod::class)->fillForm($this->data())->call('create')->assertHasNoFormErrors();
        $method = ShippingMethod::query()->sole();
        $code = $method->code;
        $this->assertSame(400, $method->price_minor);
        $this->assertSame(['HR'], $method->countries);
        Livewire::test(EditShippingMethod::class, ['record' => $method->id])->fillForm(['price_eur' => '6.55', 'enabled' => false])->call('save')->assertHasNoFormErrors();
        $method->refresh();
        $this->assertSame(655, $method->price_minor);
        $this->assertFalse($method->enabled);
        $this->assertSame(2, $method->version);
        $this->assertSame($code, $method->code);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'shipping', 'event' => 'updated', 'subject_id' => $method->id]);
        $this->assertFalse(ShippingMethodResource::canDelete($method));
    }

    public function test_shipping_is_taxed_by_lunar_and_snapshotted_on_order(): void
    {
        $method = app(ShippingMethodEditor::class)->save($this->data());
        $cart = $this->cart();
        $options = app(ShippingOptions::class)->forCart($cart);
        $this->assertCount(1, $options);
        $cart->setShippingOption($options[0]);
        $cart->recalculate();
        $this->assertSame(400, $cart->shippingSubTotal->value);
        $this->assertSame(100, $cart->shippingTaxTotal->value);
        $this->assertSame(3500, $cart->total->value);
        $order = $cart->createOrder();
        $this->assertSame('Testna dostava', $order->shippingLines->sole()->description);
        $this->assertSame(500, $order->shipping_total->value);
        $method->forceFill(['name' => 'Novo ime', 'price_minor' => 900])->save();
        $this->assertSame('Testna dostava', $order->fresh()->shippingLines->sole()->description);
        $this->assertSame(500, $order->fresh()->shipping_total->value);
    }

    public function test_disabled_or_foreign_country_methods_disappear_in_same_request(): void
    {
        $method = app(ShippingMethodEditor::class)->save($this->data());
        $cart = $this->cart();
        $identifier = ShippingOptions::PREFIX.$method->code;
        $this->assertNotNull(ShippingManifest::getOption($cart, $identifier));
        $method->forceFill(['enabled' => false])->save();
        $this->assertNull(ShippingManifest::getOption($cart, $identifier));
        $method->forceFill(['enabled' => true, 'countries' => ['SI']])->save();
        $this->assertNull(ShippingManifest::getOption($cart, $identifier));
        $this->assertSame([], app(ShippingOptions::class)->forCart($cart));
        $method->forceFill(['countries' => ['HR']])->save();
        $this->assertNotNull(ShippingManifest::getOption($cart, $identifier));
        $other = Cart::query()->create(['currency_id' => $cart->currency_id, 'channel_id' => $cart->channel_id]);
        $this->assertNull(ShippingManifest::getOption($other, $identifier));
        $this->assertNotNull(ShippingManifest::getOption($cart, $identifier));
    }

    public function test_stale_edit_cannot_overwrite_new_tariff_and_bad_inputs_never_save(): void
    {
        $editor = app(ShippingMethodEditor::class);
        $method = $editor->save($this->data());
        $old = ShippingMethodEditor::formData($method);
        $editor->save([...$old, 'price_eur' => '5.00'], $method);
        try {
            $editor->save([...$old, 'price_eur' => '1.00'], $method);
            $this->fail('A stale edit overwrote the tariff.');
        } catch (ValidationException) {
            $this->assertSame(500, $method->fresh()->price_minor);
        }
        foreach ([['countries' => []], ['countries' => ['ZZ']], ['countries' => ['hr']], ['price_eur' => '-1'], ['price_eur' => '1.234'], ['price_eur' => '99999999'], ['tax_class_id' => 999999]] as $invalid) {
            try {
                $editor->save([...$this->data(), ...$invalid]);
                $this->fail('Invalid shipping settings were accepted.');
            } catch (ValidationException) {
                $this->assertSame(1, ShippingMethod::query()->count());
            }
        }
    }

    public function test_customers_and_unenrolled_admins_cannot_manage_shipping(): void
    {
        $customer = User::factory()->create();
        $unenrolled = User::factory()->create();
        $unenrolled->forceFill(['is_admin' => true])->save();
        foreach ([$customer, $unenrolled] as $user) {
            $this->actingAs($user);
            try {
                app(ShippingMethodEditor::class)->save($this->data());
                $this->fail('Unauthorized shipping configuration was accepted.');
            } catch (AuthorizationException) {
                $this->assertSame(0, ShippingMethod::query()->count());
            }
            $this->assertFalse(ShippingMethodResource::canViewAny());
        }
    }

    public function test_demo_shipping_is_explicit_development_only_and_preserves_edits(): void
    {
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
        $method = ShippingMethod::query()->where('code', SeedDemoShipping::CODE)->sole();
        $method->forceFill(['price_minor' => 123, 'enabled' => false])->save();
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
        $this->assertSame(123, $method->fresh()->price_minor);
        $this->assertFalse($method->fresh()->enabled);
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('oneshop:demo-shipping')->assertFailed();
        $this->assertSame(1, ShippingMethod::query()->count());
    }
}
