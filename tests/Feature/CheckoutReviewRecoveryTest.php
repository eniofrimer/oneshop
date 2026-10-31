<?php

namespace Tests\Feature;

use App\Checkout\DevelopmentCheckoutService;
use App\Checkout\StripeSandboxCheckout;
use App\Models\ShippingMethod;
use App\Storefront\GuestCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Lunar\Exceptions\Carts\CartException;
use Lunar\Models\Cart;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\ProductVariant;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StripeTestCredentials;
use Tests\TestCase;

class CheckoutReviewRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US', 'oneshop.demo_checkout' => true,
            'payments.stripe_sandbox.enabled' => true, 'payments.stripe_sandbox.account' => 'acct_testMerchant',
            'payments.stripe_sandbox.secret_key' => StripeTestCredentials::secretKey(),
            'payments.stripe_sandbox.webhook_secret' => StripeTestCredentials::webhookSecret()]);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
    }

    public static function modes(): array
    {
        return ['demo' => ['store.checkout'], 'Stripe sandbox' => ['store.stripe']];
    }

    private function add(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 2])->assertRedirect(route('store.cart'));
    }

    private function prepare(string $root): void
    {
        $this->add();
        $this->post(route($root.'.address'), ['first_name' => 'Ana', 'last_name' => 'Recovery',
            'contact_email' => 'recovery@example.test', 'contact_phone' => '+385910000000',
            'line_one' => 'QA ulica 1', 'line_two' => '0', 'city' => 'Zagreb', 'postcode' => '10000',
            'country_id' => Country::query()->where('iso2', 'HR')->sole()->id])->assertRedirect(route($root.'.review'));
        $this->get(route($root.'.review'))->assertOk();
        $this->assertIsString(session('oneshop.checkout_quote'));
    }

    #[DataProvider('modes')]
    public function test_lost_stock_returns_to_an_editable_cart_without_an_order_or_losing_the_address(string $root): void
    {
        $this->prepare($root);
        ProductVariant::query()->where('sku', 'DEMO-CUP')->update(['stock' => 0]);
        $this->from(route($root.'.review'))->get(route($root.'.review'))
            ->assertRedirect(route('store.cart'))->assertSessionHasErrors('cart');
        $this->get(route('store.cart'))->assertOk()->assertSee('Neki artikli više nisu dostupni.');
        $this->assertNull(session('oneshop.checkout_quote'));
        $cart = Cart::query()->findOrFail(session(GuestCart::SESSION_KEY));
        $this->assertSame(2, $cart->lines->sole()->quantity);
        $this->assertSame('Ana', $cart->billingAddress->first_name);
        $this->assertSame('0', $cart->billingAddress->line_two);
        $this->assertSame(0, Order::query()->count());
    }

    #[DataProvider('modes')]
    public function test_missing_address_returns_to_the_matching_address_form(string $root): void
    {
        $this->add();
        $this->withSession(['oneshop.checkout_quote' => str_repeat('a', 64)])
            ->from(route($root.'.review'))->get(route($root.'.review'))->assertRedirect(route($root))
            ->assertSessionHasErrors('address');
        $this->assertNull(session('oneshop.checkout_quote'));
        $this->get(route($root))->assertOk()->assertViewHas('address', []);
        $this->assertSame(0, Order::query()->count());
    }

    #[DataProvider('modes')]
    public function test_removed_delivery_returns_to_the_matching_form_and_keeps_saved_values(string $root): void
    {
        $this->prepare($root);
        ShippingMethod::query()->sole()->forceFill(['enabled' => false])->save();
        $this->from(route($root.'.review'))->get(route($root.'.review'))->assertRedirect(route($root))
            ->assertSessionHasErrors('shipping');
        $this->assertNull(session('oneshop.checkout_quote'));
        $this->get(route($root))->assertOk()->assertViewHas('address', fn (array $address): bool => $address['first_name'] === 'Ana' && $address['line_two'] === '0');
        $this->assertSame(0, Order::query()->count());
    }

    #[DataProvider('modes')]
    public function test_json_review_retains_422_validation_without_an_html_redirect(string $root): void
    {
        $this->prepare($root);
        ProductVariant::query()->where('sku', 'DEMO-CUP')->update(['stock' => 0]);
        $response = $this->getJson(route($root.'.review'))->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertFalse($response->headers->has('Location'));
        $this->assertNull(session('oneshop.checkout_quote'));
        $this->assertSame(0, Order::query()->count());
    }

    #[DataProvider('modes')]
    public function test_native_cart_exception_also_has_a_fixed_recovery_destination(string $root): void
    {
        $type = $root === 'store.checkout' ? DevelopmentCheckoutService::class : StripeSandboxCheckout::class;
        $this->mock($type, fn (MockInterface $mock) => $mock->shouldReceive('review')->once()
            ->andThrow(new CartException(new MessageBag(['cart' => ['Košarica je promijenjena.']]))));
        $this->withSession(['oneshop.checkout_quote' => str_repeat('a', 64)])
            ->from(route($root.'.review'))->get(route($root.'.review'))->assertRedirect(route('store.cart'))
            ->assertSessionHasErrors('cart');
        $this->assertNull(session('oneshop.checkout_quote'));
    }
}
