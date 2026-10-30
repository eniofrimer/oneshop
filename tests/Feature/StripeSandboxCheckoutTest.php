<?php

namespace Tests\Feature;

use App\Checkout\StripeSandboxCheckout;
use App\Inventory\StockAvailability;
use App\Models\DevelopmentCheckout;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\ShippingMethod;
use App\Models\StockReservation;
use App\Models\User;
use App\Payments\StripeSandboxConfiguration;
use App\Storefront\GuestCart;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Lunar\Models\Cart;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\Price;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\StripeFakeTransport;
use Tests\TestCase;

class StripeSandboxCheckoutTest extends TestCase
{
    use DatabaseTruncation;

    private StripeFakeTransport $transport;

    private ClientInterface $previousTransport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US', 'oneshop.demo_checkout' => true, 'app.url' => 'http://shop.example.test',
            'payments.stripe_sandbox.enabled' => true, 'payments.stripe_sandbox.account' => 'acct_testMerchant',
            'payments.stripe_sandbox.secret_key' => 'sk_test_OneShopSyntheticKey0000',
            'payments.stripe_sandbox.webhook_secret' => 'whsec_OneShopSyntheticSecret0000']);
        $this->previousTransport = ApiRequestor::httpClient();
        $this->transport = new StripeFakeTransport;
        ApiRequestor::setHttpClient($this->transport);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            ApiRequestor::setHttpClient($this->previousTransport);
            ApiRequestor::resetTelemetry();
            parent::tearDown();
        }
    }

    private function variant(): ProductVariant
    {
        return ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
    }

    private function prepare(): string
    {
        $this->post(route('store.cart.add'), ['variant_id' => $this->variant()->id, 'quantity' => 2])->assertRedirect();
        $this->get(route('store.cart'))->assertOk()->assertSee('Stripe sandbox checkout');
        $this->get(route('store.stripe'))->assertOk()->assertSee('STRIPE SANDBOX')
            ->assertSee(route('store.stripe.address'), false);
        $this->post(route('store.stripe.address'), [
            'first_name' => 'Ana', 'last_name' => 'Sandbox', 'contact_email' => 'checkout@example.test',
            'contact_phone' => '+385 91 000 0000', 'line_one' => 'Testna ulica 1', 'line_two' => '0',
            'city' => 'Zagreb', 'postcode' => '10000', 'country_id' => Country::query()->where('iso2', 'HR')->sole()->id,
        ])->assertRedirect(route('store.stripe.review'));
        $this->get(route('store.stripe.review'))->assertOk()->assertSee('Pripremi Stripe testnu narudžbu')
            ->assertSee('name="stripe_test_acknowledged"', false)->assertSee(route('store.stripe.place'), false)
            ->assertDontSee('name="demo_acknowledged"', false);

        return session('oneshop.checkout_quote');
    }

    private function place(): PaymentAttempt
    {
        $quote = $this->prepare();
        $this->post(route('store.stripe.place'), ['quote' => $quote, 'stripe_test_acknowledged' => 1,
            'amount_minor' => 1, 'currency_code' => 'USD', 'provider_account' => 'acct_attacker'])->assertRedirect();

        return PaymentAttempt::query()->sole();
    }

    private function providerSession(PaymentAttempt $attempt, bool $paid = false, string $status = 'open'): array
    {
        return ['id' => 'cs_test_oneSession', 'object' => 'checkout.session', 'livemode' => false, 'mode' => 'payment',
            'client_reference_id' => $attempt->id, 'amount_total' => $attempt->amount_minor, 'currency' => 'eur',
            'payment_status' => $paid ? 'paid' : 'unpaid', 'status' => $paid ? 'complete' : $status,
            'url' => $paid || $status !== 'open' ? null : 'https://checkout.stripe.com/c/pay/synthetic',
            'payment_intent' => $paid ? ['id' => 'pi_onePayment', 'object' => 'payment_intent', 'livemode' => false,
                'status' => 'succeeded', 'amount_received' => $attempt->amount_minor, 'currency' => 'eur',
                'metadata' => ['oneshop_attempt' => $attempt->id],
                'latest_charge' => ['id' => 'ch_oneCapture', 'object' => 'charge', 'livemode' => false,
                    'payment_intent' => 'pi_onePayment', 'paid' => true, 'captured' => true,
                    'amount_captured' => $attempt->amount_minor, 'currency' => 'eur',
                    'refunded' => false, 'amount_refunded' => 0, 'disputed' => false]] : null];
    }

    private function respondWith(array|\Throwable $session): void
    {
        $this->transport->respond(['id' => 'acct_testMerchant', 'object' => 'account']);
        $this->transport->respond($session);
    }

    public function test_public_placement_freezes_server_total_and_reserves_once_without_network_or_demo_conversion(): void
    {
        $attempt = $this->place();
        $order = $attempt->order;
        $this->assertSame('stripe-test', $order->meta['oneshop_mode']);
        $this->assertSame('EUR', $attempt->currency_code);
        $this->assertSame((int) $order->getRawOriginal('total'), $attempt->amount_minor);
        $this->assertSame('acct_testMerchant', $attempt->provider_account);
        $this->assertNull($order->user_id);
        $this->assertNull($order->customer_id);
        $this->assertNotNull($order->placed_at);
        $this->assertSame('prepared', $attempt->status);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->assertSame(20, $this->variant()->stock);
        $this->assertSame([], $this->transport->requests);
        $this->assertSame(0, DevelopmentCheckout::query()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->post(route('store.stripe.place'), ['quote' => session('oneshop.checkout_quote'), 'stripe_test_acknowledged' => 1])
            ->assertRedirect(route('store.stripe.return', $attempt->id));
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, StockReservation::query()->count());
        $this->assertContains($attempt->id, session(StripeSandboxCheckout::OWNED_KEY));
    }

    public function test_return_get_and_forged_query_parameters_cannot_confirm_or_cancel_a_payment(): void
    {
        $attempt = $this->place();
        $before = $attempt->getRawOriginal();
        $this->get(route('store.stripe.return', $attempt->id).'?paid=1&status=succeeded&session_id=cs_test_foreign')
            ->assertOk()->assertSee('Spremno za Stripe test.')->assertSee('DEMO-CUP')
            ->assertSee('Povratak na ovu stranicu sam po sebi ne potvrđuje naplatu.')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertDontSee('Stripe testna kupnja je potvrđena');
        $this->assertSame($before, $attempt->fresh()->getRawOriginal());
        $this->assertSame([], $this->transport->requests);
        $this->assertSame(0, PaymentReceipt::query()->count());
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->get(route('store.stripe.cancel', $attempt->id))->assertStatus(405);
    }

    public function test_owner_launch_redirects_only_to_verified_stripe_then_confirmed_capture_completes_once(): void
    {
        $attempt = $this->place();
        $this->respondWith($this->providerSession($attempt));
        $this->post(route('store.stripe.launch', $attempt->id))->assertRedirect('https://checkout.stripe.com/c/pay/synthetic');
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(0, PaymentReceipt::query()->count());
        $this->respondWith($this->providerSession($attempt, true));
        $this->post(route('store.stripe.refresh', $attempt->id), ['outcome' => 'cancelled'])->assertRedirect(route('store.stripe.return', $attempt->id));
        $this->get(route('store.stripe.return', $attempt->id))->assertOk()->assertSee('Stripe testna kupnja je potvrđena')
            ->assertDontSee('Nastavi na Stripe test');
        $this->respondWith($this->providerSession($attempt, true));
        $this->post(route('store.stripe.refresh', $attempt->id))->assertRedirect();
        $this->assertSame(18, $this->variant()->stock);
        $this->assertSame(1, PaymentReceipt::query()->count());
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(0, StockAvailability::held($this->variant()->id));
    }

    public function test_owner_timeout_keeps_the_same_attempt_and_hold_until_a_confirmed_provider_expiry(): void
    {
        $attempt = $this->place();
        $this->respondWith(new ApiConnectionException('Synthetic lost response.'));
        $this->post(route('store.stripe.launch', $attempt->id))->assertRedirect(route('store.stripe.return', $attempt->id));
        $this->get(route('store.stripe.return', $attempt->id))->assertOk()->assertSee('Čekamo pouzdanu potvrdu.');
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->respondWith($this->providerSession($attempt));
        $this->post(route('store.stripe.launch', $attempt->id))->assertRedirect('https://checkout.stripe.com/c/pay/synthetic');
        $this->transport->respond(['id' => 'acct_testMerchant', 'object' => 'account']);
        $this->transport->respond($this->providerSession($attempt, status: 'expired'));
        $this->transport->respond($this->providerSession($attempt, status: 'expired'));
        $this->post(route('store.stripe.cancel', $attempt->id))->assertRedirect(route('store.stripe.return', $attempt->id));
        $this->get(route('store.stripe.return', $attempt->id))->assertOk()->assertSee('Stripe je potvrdio istek.');
        $this->assertSame(0, StockAvailability::held($this->variant()->id));
        $this->assertSame(20, $this->variant()->stock);
        $this->assertSame(0, PaymentReceipt::query()->count());
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    public function test_another_session_and_a_demo_simulation_cannot_act_on_the_provider_attempt(): void
    {
        $attempt = $this->place();
        $this->post(route('store.checkout.simulate', $attempt->id), ['outcome' => 'paid'])->assertNotFound();
        $this->flushSession();
        $this->get(route('store.stripe.return', $attempt->id))->assertNotFound();
        foreach (['launch', 'refresh', 'cancel'] as $action) {
            $this->post(route('store.stripe.'.$action, $attempt->id), ['outcome' => 'paid', 'cart_id' => $attempt->order->cart_id])->assertNotFound();
        }
        $this->assertSame('prepared', $attempt->fresh()->status);
        $this->assertSame([], $this->transport->requests);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_price_or_content_changed_after_review_rolls_back_the_whole_new_order(): void
    {
        $quote = $this->prepare();
        Price::query()->whereMorphedTo('priceable', $this->variant())->update(['price' => 9999]);
        $this->post(route('store.stripe.place'), ['quote' => $quote, 'stripe_test_acknowledged' => 1])->assertSessionHasErrors('quote');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertNull(Cart::query()->findOrFail(session(GuestCart::SESSION_KEY))->completed_at);
        $this->assertSame([], $this->transport->requests);
    }

    public function test_lost_stock_after_review_never_leaves_a_placed_unreserved_order(): void
    {
        $quote = $this->prepare();
        $this->variant()->update(['stock' => 1]);
        $this->post(route('store.stripe.place'), ['quote' => $quote, 'stripe_test_acknowledged' => 1])->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(0, StockReservation::query()->count());
    }

    public function test_a_foreign_checkout_url_is_not_used_as_a_redirect(): void
    {
        $attempt = $this->place();
        $session = $this->providerSession($attempt);
        $session['url'] = 'https://attacker.example.test/pay';
        $this->respondWith($session);
        $this->post(route('store.stripe.launch', $attempt->id))->assertRedirect(route('store.stripe.return', $attempt->id));
        $this->assertSame('unknown', $attempt->fresh()->status);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->assertSame(0, PaymentReceipt::query()->count());
    }

    public function test_completed_cart_cannot_be_submitted_to_the_other_payment_mode(): void
    {
        $this->place();
        $this->post(route('store.checkout.place'), ['quote' => session('oneshop.checkout_quote'), 'demo_acknowledged' => 1])
            ->assertSessionHasErrors('cart');
        $this->assertSame(0, DevelopmentCheckout::query()->count());
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->flushSession();
        $quote = $this->prepare();
        $this->post(route('store.checkout.place'), ['quote' => $quote, 'demo_acknowledged' => 1])->assertRedirect();
        $this->post(route('store.stripe.place'), ['quote' => $quote, 'stripe_test_acknowledged' => 1])->assertSessionHasErrors('cart');
        $this->assertSame(1, DevelopmentCheckout::query()->count());
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(2, Order::query()->count());
        $this->assertSame([], $this->transport->requests);
    }

    public function test_zero_total_is_refused_without_creating_a_provider_attempt(): void
    {
        Price::query()->whereMorphedTo('priceable', $this->variant())->update(['price' => 0]);
        ShippingMethod::query()->update(['price_minor' => 0]);
        $quote = $this->prepare();
        $this->post(route('store.stripe.place'), ['quote' => $quote, 'stripe_test_acknowledged' => 1])->assertSessionHasErrors('cart');
        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame([], $this->transport->requests);
    }

    public function test_provider_checkout_works_with_the_demo_simulator_disabled_and_does_not_attach_the_administrator(): void
    {
        config(['oneshop.demo_checkout' => false]);
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($owner);
        $attempt = $this->place();
        $this->assertNull($attempt->order->user_id);
        $this->assertNull($attempt->order->customer_id);
        $this->get(route('store.checkout'))->assertNotFound();
        $this->assertSame('prepared', $attempt->status);
    }

    #[DataProvider('unavailableConfigurations')]
    public function test_routes_and_checkout_choice_are_closed_without_a_complete_test_configuration(string $case): void
    {
        match ($case) {
            'disabled' => config(['payments.stripe_sandbox.enabled' => false]),
            'no-key' => config(['payments.stripe_sandbox.secret_key' => null]),
            'live-key' => config(['payments.stripe_sandbox.secret_key' => 'sk_live_ForbiddenSyntheticKey']),
            'no-webhook' => config(['payments.stripe_sandbox.webhook_secret' => null]),
            'no-account' => config(['payments.stripe_sandbox.account' => null]),
            'production' => $this->app->instance('env', 'production'),
        };
        if ($case === 'production') {
            // Reach the environment guard directly. Its refusal must not depend
            // on the separate request-forgery protection rejecting first.
            $this->withoutMiddleware(PreventRequestForgery::class);
        }
        $this->assertFalse(StripeSandboxConfiguration::available());
        $this->post(route('store.cart.add'), ['variant_id' => $this->variant()->id, 'quantity' => 1])->assertRedirect();
        $this->get(route('store.cart'))->assertOk()->assertDontSee('Stripe sandbox checkout');
        $this->get(route('store.stripe'))->assertNotFound();
        $this->post(route('store.stripe.place'), ['quote' => str_repeat('a', 64), 'stripe_test_acknowledged' => 1])->assertNotFound();
        $this->assertSame([], $this->transport->requests);
        $this->assertSame(0, PaymentAttempt::query()->count());
    }

    public static function unavailableConfigurations(): array
    {
        return array_map(fn (string $case) => [$case], ['disabled', 'no-key', 'live-key', 'no-webhook', 'no-account', 'production']);
    }

    public function test_public_payment_post_still_requires_csrf_in_a_real_local_environment(): void
    {
        $this->app->instance('env', 'local');
        $this->assertTrue(StripeSandboxConfiguration::available());
        $this->post(route('store.stripe.place'), ['quote' => str_repeat('a', 64), 'stripe_test_acknowledged' => 1])
            ->assertStatus(419);
        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame([], $this->transport->requests);
    }
}
