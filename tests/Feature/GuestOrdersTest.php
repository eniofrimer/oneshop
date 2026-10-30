<?php

namespace Tests\Feature;

use App\Checkout\DevelopmentCheckoutService;
use App\Checkout\StripeSandboxCheckout;
use App\Models\DevelopmentCheckout;
use App\Models\PaymentAttempt;
use App\Models\StockReservation;
use App\Storefront\GuestCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Models\Cart;
use Lunar\Models\Country;
use Lunar\Models\ProductVariant;
use Stripe\ApiRequestor;
use Tests\Support\StripeFakeTransport;
use Tests\Support\StripeTestCredentials;
use Tests\TestCase;

class GuestOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US', 'oneshop.demo_checkout' => true]);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
    }

    private function place(bool $stripe = false): DevelopmentCheckout|PaymentAttempt
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertRedirect();
        $prefix = $stripe ? 'store.stripe' : 'store.checkout';
        $this->post(route($prefix.'.address'), ['first_name' => 'Session', 'last_name' => 'Guest',
            'contact_email' => 'private-guest@example.test', 'contact_phone' => '+385910000000',
            'line_one' => 'Private guest address', 'city' => 'Zagreb', 'postcode' => '10000',
            'country_id' => Country::query()->where('iso2', 'HR')->sole()->id])->assertRedirect();
        $this->get(route($prefix.'.review'))->assertOk();
        $this->post(route($prefix.'.place'), ['quote' => session('oneshop.checkout_quote'),
            $stripe ? 'stripe_test_acknowledged' : 'demo_acknowledged' => 1])->assertRedirect();

        return $stripe ? PaymentAttempt::query()->sole() : DevelopmentCheckout::query()->where('cart_id', session(GuestCart::SESSION_KEY))->sole();
    }

    public function test_empty_order_history_is_private_and_does_not_create_a_cart(): void
    {
        $this->get(route('store.orders'))->assertOk()->assertSee('Prvi favorit tek te čeka.')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('store.home'))->assertOk()->assertSee('data-cart-count>0', false);
        $this->assertSame(0, Cart::query()->count());
        $this->assertSame(0, DevelopmentCheckout::query()->count());
    }

    public function test_only_session_owned_orders_are_listed_and_query_parameters_cannot_grant_access(): void
    {
        $first = $this->place();
        $this->flushSession();
        $second = $this->place();
        $this->get(route('store.orders', ['checkout' => $first->id, 'email' => 'private-guest@example.test']))
            ->assertOk()->assertSee($second->order->reference)->assertDontSee($first->order->reference)
            ->assertDontSee('Private guest address')->assertDontSee('private-guest@example.test');
        $this->withSession([DevelopmentCheckoutService::OWNED_KEY => [$first->id]])
            ->get(route('store.orders'))->assertOk()->assertSee($first->order->reference)
            ->assertDontSee($second->order->reference)->assertSee(route('store.checkout.payment', $first->id), false);
    }

    public function test_history_get_does_not_expire_orders_release_holds_or_change_payment_state(): void
    {
        $checkout = $this->place();
        $before = $checkout->fresh()->getRawOriginal();
        $reservations = StockReservation::query()->get()->map->getRawOriginal()->all();
        $this->travel(31)->minutes();
        $this->get(route('store.orders'))->assertOk()->assertSee('Čeka testnu potvrdu');
        $this->assertSame($before, $checkout->fresh()->getRawOriginal());
        $this->assertSame($reservations, StockReservation::query()->get()->map->getRawOriginal()->all());
    }

    public function test_paid_order_remains_reachable_when_a_new_cart_is_started_and_badge_counts_units(): void
    {
        $checkout = $this->place();
        $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => 'paid'])->assertRedirect();
        $this->get(route('store.home'))->assertOk()->assertSee('data-cart-count>0', false);
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 3])->assertRedirect();
        $this->get(route('store.orders'))->assertOk()->assertSee('Testno potvrđena')
            ->assertSee('Otvori potvrdu')->assertSee($checkout->order->reference)->assertSee('data-cart-count>3', false);
        $this->get(route('store.checkout.payment', $checkout->id))->assertOk()->assertSee('Testna kupnja je uspjela');
    }

    public function test_malformed_session_capabilities_are_ignored_before_uuid_queries(): void
    {
        $this->withSession([DevelopmentCheckoutService::OWNED_KEY => ['not-a-uuid', '1', [], true],
            StripeSandboxCheckout::OWNED_KEY => 'invalid'])->get(route('store.orders'))
            ->assertOk()->assertSee('Prvi favorit tek te čeka.');
    }

    public function test_disabled_and_production_histories_remain_closed_even_with_old_capabilities(): void
    {
        config(['oneshop.demo_checkout' => false]);
        $this->get(route('store.orders'))->assertNotFound();
        config(['oneshop.demo_checkout' => true]);
        $this->app->instance('env', 'production');
        $this->get(route('store.orders'))->assertNotFound();
        $this->get(route('store.home'))->assertOk()->assertDontSee(route('store.orders'), false);
    }

    public function test_stripe_history_is_account_scoped_and_performs_no_provider_request(): void
    {
        config(['payments.stripe_sandbox.enabled' => true, 'payments.stripe_sandbox.account' => 'acct_historyTest',
            'payments.stripe_sandbox.secret_key' => StripeTestCredentials::secretKey(),
            'payments.stripe_sandbox.webhook_secret' => StripeTestCredentials::webhookSecret()]);
        $previous = ApiRequestor::httpClient();
        $fake = new StripeFakeTransport;
        ApiRequestor::setHttpClient($fake);
        try {
            $attempt = $this->place(stripe: true);
            $this->get(route('store.orders'))->assertOk()->assertSee($attempt->order->reference)
                ->assertSee('Stripe sandbox')->assertSee(route('store.stripe.return', $attempt->id), false)
                ->assertDontSee('acct_historyTest');
            config(['payments.stripe_sandbox.account' => 'acct_otherHistory']);
            $this->get(route('store.orders'))->assertOk()->assertDontSee($attempt->order->reference);
            $this->assertSame([], $fake->requests);
        } finally {
            ApiRequestor::setHttpClient($previous);
        }
    }
}
