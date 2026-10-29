<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Lunar\DataTypes\Price as PriceData;
use Lunar\DataTypes\ShippingOption;
use Lunar\DiscountTypes\AmountOff;
use Lunar\Facades\Discounts;
use Lunar\Facades\ShippingManifest;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Jobs\Orders\MarkAsNewCustomer;
use Lunar\Models\Cart;
use Lunar\Models\CartAddress;
use Lunar\Models\Channel;
use Lunar\Models\Country;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Discount;
use Lunar\Models\Language;
use Lunar\Models\Order;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Lunar\Models\TaxRate;
use Lunar\Models\TaxRateAmount;
use Lunar\Models\TaxZone;
use Tests\TestCase;

class CommerceFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertContains(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb'], 'Run commerce integration tests on a supported server database.');

        app()->setLocale('hr');
        // Vendor address factories use formats absent from Faker's hr_HR provider.
        // Keep storefront translations Croatian while using complete fixture data.
        config()->set('app.faker_locale', 'en_US');
        config()->set('lunar.pricing.stored_inclusive_of_tax', false);
        config()->set('lunar.taxes.driver', 'system');

        // Customer classification is asynchronous and outside this pricing proof.
        Queue::fake([MarkAsNewCustomer::class]);
    }

    public function test_guest_cart_becomes_an_order_with_tax_shipping_and_historical_snapshots(): void
    {
        $fixture = $this->createPurchasableCart();
        $cart = $fixture['cart']->recalculate();

        // Two EUR 12.00 net items plus EUR 4.00 net delivery, at 25% tax.
        $this->assertSame(2400, $cart->subTotal->value);
        $this->assertSame(400, $cart->shippingSubTotal->value);
        $this->assertSame(700, $cart->taxTotal->value);
        $this->assertSame(3500, $cart->total->value);

        $order = $cart->createOrder();
        $order->update(['placed_at' => now()]);

        $this->assertNull($order->user_id);
        $this->assertSame('EUR', $order->currency_code);
        $this->assertSame(3500, $order->total->value);
        $this->assertCount(1, $order->productLines);
        $this->assertCount(1, $order->shippingLines);
        $this->assertCount(2, $order->addresses);

        $line = $order->productLines->sole();

        $this->assertSame('Majica', $line->description);
        $this->assertSame('TEST-BLUE-M', $line->identifier);
        $this->assertSame(1200, $line->unit_price->value);
        $this->assertSame(2, $line->quantity);
        $this->assertSame('Ulica kupca 1', $order->billingAddress->line_one);

        // Changing live catalogue and address records must not rewrite a placed order.
        $fixture['product']->update([
            'attribute_data' => collect([
                'name' => new TranslatedText(['hr' => 'Novo ime', 'en' => 'New name']),
            ]),
        ]);
        $fixture['variant']->update(['sku' => 'CHANGED-SKU']);
        $fixture['price']->update(['price' => 9999]);
        $cart->billingAddress->update(['line_one' => 'Druga ulica 9']);

        $order = $order->fresh();
        $historicalLine = $order->productLines->sole();

        $this->assertSame('Majica', $historicalLine->description);
        $this->assertSame('TEST-BLUE-M', $historicalLine->identifier);
        $this->assertSame(1200, $historicalLine->unit_price->value);
        $this->assertSame(3500, $order->total->value);
        $this->assertSame('Ulica kupca 1', $order->billingAddress->line_one);
    }

    public function test_rebuilding_a_draft_order_preserves_a_single_use_coupon_without_duplicate_consumption(): void
    {
        $fixture = $this->createPurchasableCart();

        $discount = Discount::factory()->create([
            'type' => AmountOff::class,
            'name' => 'Test five euro coupon',
            'coupon' => 'SAVE5',
            'uses' => 0,
            'max_uses' => 1,
            'data' => [
                'fixed_value' => true,
                'fixed_values' => ['EUR' => 500],
            ],
        ]);
        $discount->customerGroups()->sync([
            $fixture['group']->id => ['enabled' => true, 'starts_at' => now()->subMinute()],
        ]);
        $discount->channels()->sync([
            $fixture['channel']->id => ['enabled' => true, 'starts_at' => now()->subMinute()],
        ]);

        $fixture['cart']->update(['coupon_code' => 'SAVE5']);
        $cart = $fixture['cart']->fresh();
        $first = $cart->createOrder();

        $this->assertSame(500, $first->discount_total->value);
        $this->assertSame(575, $first->tax_total->value);
        $this->assertSame(2875, $first->total->value);
        $this->assertSame(1, $discount->fresh()->uses);

        // A fresh request after a declined card must reuse the draft and coupon use.
        Discounts::resetDiscounts();
        $retry = Cart::findOrFail($cart->id)->createOrder();

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, Order::query()->where('cart_id', $cart->id)->count());
        $this->assertSame(500, $retry->discount_total->value);
        $this->assertSame(2875, $retry->total->value);
        $this->assertSame(1, $discount->fresh()->uses);
        $this->assertCount(2, $retry->lines);
    }

    public function test_our_filament_admin_login_renders_with_the_same_commerce_application(): void
    {
        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSee('type="password"', false);
    }

    /**
     * Uses Lunar's published factories and public cart API; no old admin package.
     * Tax is a controlled arithmetic fixture, not automatic VAT policy for buyers.
     *
     * @return array{cart: Cart, product: Product, variant: ProductVariant, price: Price, channel: Channel, group: CustomerGroup}
     */
    private function createPurchasableCart(): array
    {
        Language::factory()->create(['code' => 'hr', 'name' => 'Hrvatski', 'default' => true]);
        Language::factory()->create(['code' => 'en', 'name' => 'English', 'default' => false]);

        $currency = Currency::factory()->create([
            'code' => 'EUR',
            'name' => 'Euro',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'default' => true,
        ]);
        $channel = Channel::factory()->create(['handle' => 'web', 'default' => true]);
        $group = CustomerGroup::factory()->create(['handle' => 'retail', 'default' => true]);
        $taxClass = TaxClass::factory()->create(['name' => 'Test standard tax', 'default' => true]);
        $taxZone = TaxZone::factory()->create([
            'name' => 'Test zone',
            'zone_type' => 'country',
            'price_display' => 'tax_inclusive',
            'default' => true,
            'active' => true,
        ]);
        $taxRate = TaxRate::factory()->create([
            'name' => 'Test VAT 25%',
            'tax_zone_id' => $taxZone->id,
            'priority' => 1,
        ]);
        TaxRateAmount::factory()->create([
            'tax_rate_id' => $taxRate->id,
            'tax_class_id' => $taxClass->id,
            'percentage' => 25,
        ]);

        $product = Product::factory()->create([
            'status' => 'published',
            'attribute_data' => collect([
                'name' => new TranslatedText(['hr' => 'Majica', 'en' => 'T-shirt']),
                'description' => new TranslatedText(['hr' => 'Testni proizvod', 'en' => 'Test product']),
            ]),
        ]);
        $product->channels()->sync([
            $channel->id => ['enabled' => true, 'starts_at' => now()->subMinute()],
        ]);
        $product->customerGroups()->sync([
            $group->id => ['enabled' => true, 'visible' => true, 'purchasable' => true],
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'tax_class_id' => $taxClass->id,
            'sku' => 'TEST-BLUE-M',
            'unit_quantity' => 1,
            'stock' => 10,
            'backorder' => 0,
            'purchasable' => 'in_stock',
            'shippable' => true,
        ]);
        $price = Price::factory()->create([
            'price' => 1200,
            'compare_price' => null,
            'min_quantity' => 1,
            'currency_id' => $currency->id,
            'priceable_type' => $variant->getMorphClass(),
            'priceable_id' => $variant->id,
        ]);
        $cart = Cart::factory()->create([
            'currency_id' => $currency->id,
            'channel_id' => $channel->id,
        ]);
        $country = Country::factory()->create([
            'name' => 'Croatia',
            'iso2' => 'HR',
            'iso3' => 'HRV',
            'currency' => 'EUR',
        ]);

        foreach (['billing', 'shipping'] as $type) {
            CartAddress::factory()->create([
                'cart_id' => $cart->id,
                'country_id' => $country->id,
                'type' => $type,
                'first_name' => 'Test',
                'last_name' => 'Kupac',
                'line_one' => 'Ulica kupca 1',
                'city' => 'Split',
                'postcode' => '21000',
                'state' => null,
                'company_name' => null,
                'tax_identifier' => null,
            ]);
        }

        $cart->add($variant, quantity: 2);

        $shipping = new ShippingOption(
            name: 'Test delivery',
            description: 'Fixed delivery for the integration proof',
            identifier: 'test-flat-delivery',
            price: new PriceData(400, $currency, 1),
            taxClass: $taxClass,
        );
        ShippingManifest::addOption($shipping);
        $cart->setShippingOption($shipping);

        return compact('cart', 'product', 'variant', 'price', 'channel', 'group');
    }
}
