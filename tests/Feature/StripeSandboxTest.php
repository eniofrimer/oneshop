<?php

namespace Tests\Feature;

use App\Inventory\StockAvailability;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\PaymentOutbox;
use App\Models\PaymentReceipt;
use App\Models\StockReservation;
use App\Payments\PaymentLedger;
use App\Payments\ProviderCheckoutSnapshot;
use App\Payments\ProviderIdentity;
use App\Payments\StripeSandboxConfiguration;
use App\Payments\StripeSandboxGateway;
use App\Payments\StripeSandboxPayments;
use App\Storefront\GuestCart;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Lunar\Models\Cart;
use Lunar\Models\Country;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\StripeFakeTransport;
use Tests\Support\StripeTestCredentials;
use Tests\TestCase;

/** Real installed Stripe SDK/signature verification with a synthetic HTTP transport. */
class StripeSandboxTest extends TestCase
{
    // Gateway forbids networking inside a transaction, including a test wrapper.
    use DatabaseTruncation;

    private StripeFakeTransport $transport;

    private ClientInterface $previousTransport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US', 'oneshop.demo_checkout' => true,
            'payments.stripe_sandbox.enabled' => true, 'payments.stripe_sandbox.account' => 'acct_testMerchant',
            'payments.stripe_sandbox.secret_key' => StripeTestCredentials::secretKey(),
            'payments.stripe_sandbox.webhook_secret' => StripeTestCredentials::webhookSecret()]);
        $this->previousTransport = ApiRequestor::httpClient();
        $this->transport = new StripeFakeTransport;
        ApiRequestor::setHttpClient($this->transport);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        try {
            // DatabaseTruncation clears before its own next test. Also clear
            // after this test so subsequent RefreshDatabase classes see no
            // committed provider fixtures from a test without a wrapper.
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

    private function attempt(bool $bind = false): PaymentAttempt
    {
        $this->post(route('store.cart.add'), ['variant_id' => $this->variant()->id, 'quantity' => 2])->assertRedirect();
        $this->post(route('store.checkout.address'), [
            'first_name' => 'Ana', 'last_name' => 'Sandbox', 'contact_email' => 'sandbox@example.test',
            'contact_phone' => '+385 91 000 0000', 'line_one' => 'Testna ulica 1',
            'city' => 'Zagreb', 'postcode' => '10000', 'country_id' => Country::query()->where('iso2', 'HR')->sole()->id,
        ])->assertRedirect();
        $cart = Cart::query()->findOrFail(session(GuestCart::SESSION_KEY));
        foreach ($cart->lines as $line) {
            $line->update(['meta' => ['oneshop_track_stock' => true]]);
        }
        $cart->refresh()->update(['meta' => ['oneshop_mode' => 'stripe-test']]);
        $order = $cart->createOrder();
        $order->update(['placed_at' => now()]);
        $cart->update(['completed_at' => now()]);
        $attempt = app(PaymentLedger::class)->prepare($order, new ProviderIdentity('acct_testMerchant'), 'http://shop.example.test');
        if ($bind) {
            app(PaymentLedger::class)->bind($attempt, new ProviderCheckoutSnapshot(new ProviderIdentity('acct_testMerchant'),
                'cs_test_oneSession', $attempt->id, 'open', $attempt->amount_minor, 'EUR'));
        }

        return $attempt->fresh();
    }

    private function account(string $id = 'acct_testMerchant'): void
    {
        $this->transport->respond(['id' => $id, 'object' => 'account']);
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

    private function event(PaymentAttempt $attempt, string $id = 'evt_oneDelivery', string $type = 'checkout.session.completed'): array
    {
        return ['id' => $id, 'object' => 'event', 'api_version' => StripeSandboxConfiguration::API_VERSION,
            'livemode' => false, 'type' => $type, 'data' => ['object' => $this->providerSession($attempt, true)]];
    }

    private function webhook(array $data, ?int $timestamp = null, ?string $signedBody = null): TestResponse
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp ??= time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.($signedBody ?? $body),
            (string) config('payments.stripe_sandbox.webhook_secret'));

        return $this->call('POST', route('stripe.sandbox.webhook'), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $body);
    }

    public function test_creation_verifies_account_and_pins_the_actual_sdk_request(): void
    {
        $attempt = $this->attempt();
        $this->account();
        $this->transport->respond($this->providerSession($attempt));
        $result = app(StripeSandboxPayments::class)->refresh($attempt);
        $this->assertSame('pending', $result->status);
        $this->assertSame('cs_test_oneSession', $result->provider_object_id);
        $this->assertSame('https://checkout.stripe.com/c/pay/synthetic', $result->checkout_url);
        $this->assertSame(2, count($this->transport->requests));
        $this->assertSame('https://api.stripe.com/v1/account', $this->transport->requests[0]['absUrl']);
        $request = $this->transport->requests[1];
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $request['absUrl']);
        $this->assertSame($attempt->idempotency_key, $this->transport->header($request, 'Idempotency-Key'));
        $this->assertSame(StripeSandboxConfiguration::API_VERSION, $this->transport->header($request, 'Stripe-Version'));
        $expected = $attempt->creation_parameters;
        // The real SDK serializes booleans as Stripe's literal form values.
        $expected['automatic_tax']['enabled'] = 'false';
        $expected['adaptive_pricing']['enabled'] = 'false';
        $expected['allow_promotion_codes'] = 'false';
        $this->assertSame($expected, $request['params']);
        $this->assertFalse($attempt->fresh()->creation_parameters['automatic_tax']['enabled']);
        $this->assertSame(0, $request['maxNetworkRetries']);
        $this->assertSame(20, $this->variant()->stock);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
    }

    public function test_lost_creation_response_retries_the_same_key_and_frozen_parameters_after_a_new_process(): void
    {
        $attempt = $this->attempt();
        $this->account();
        $this->transport->respond(new ApiConnectionException('Synthetic connection loss after provider acceptance.'));
        $this->assertSame('unknown', app(StripeSandboxPayments::class)->refresh($attempt)->status);
        $this->assertNull($attempt->fresh()->provider_object_id);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->account();
        $this->transport->respond($this->providerSession($attempt));
        $result = app(StripeSandboxPayments::class)->refresh($attempt);
        $this->assertSame('pending', $result->status);
        $this->assertSame($this->transport->requests[1]['params'], $this->transport->requests[3]['params']);
        $this->assertSame($this->transport->header($this->transport->requests[1], 'Idempotency-Key'),
            $this->transport->header($this->transport->requests[3], 'Idempotency-Key'));
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(1, StockReservation::query()->count());
        $this->assertSame(0, PaymentReceipt::query()->count());
    }

    public function test_old_unbound_creation_never_reuses_a_potentially_pruned_key(): void
    {
        $attempt = $this->attempt();
        $this->travel(23)->hours();
        $result = app(StripeSandboxPayments::class)->refresh($attempt);
        $this->assertSame('review', $result->status);
        $this->assertSame('creation-window-closed', $result->review_reason);
        $this->assertSame([], $this->transport->requests);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->assertSame(0, PaymentReceipt::query()->count());
    }

    public function test_durable_signed_webhook_waits_for_binding_then_reads_the_current_provider_state_once(): void
    {
        $attempt = $this->attempt();
        $event = $this->event($attempt);
        $this->webhook($event)->assertOk()->assertHeaderMissing('Set-Cookie');
        $record = PaymentEvent::query()->sole();
        $this->assertFalse(app(StripeSandboxPayments::class)->process($record));
        $this->assertSame('session-not-bound', $record->fresh()->last_error_code);
        $this->assertNull($attempt->fresh()->provider_object_id);
        $this->assertSame([], $this->transport->requests);
        $this->account();
        $this->transport->respond($this->providerSession($attempt));
        app(StripeSandboxPayments::class)->refresh($attempt);
        $this->account();
        $this->transport->respond($this->providerSession($attempt, true));
        $this->assertTrue(app(StripeSandboxPayments::class)->process($record));
        $this->assertSame('processed', $record->fresh()->status);
        $this->webhook($event)->assertOk();
        $this->assertTrue(app(StripeSandboxPayments::class)->process($record));
        $this->assertSame(1, PaymentEvent::query()->count());
        $this->assertSame(1, PaymentReceipt::query()->count());
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, PaymentOutbox::query()->count());
        $this->assertSame(18, $this->variant()->stock);
        $this->assertSame(4, count($this->transport->requests));
        $this->assertStringNotContainsString('payments@example.test', json_encode($record->toArray()));
    }

    public function test_old_expiry_event_uses_fresh_paid_state_instead_of_releasing(): void
    {
        $attempt = $this->attempt(true);
        $this->webhook($this->event($attempt, type: 'checkout.session.expired'))->assertOk();
        $this->account();
        $this->transport->respond($this->providerSession($attempt, true));
        app(StripeSandboxPayments::class)->process(PaymentEvent::query()->sole());
        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame(StockReservation::CONSUMED, StockReservation::query()->sole()->state);
        $this->assertSame(18, $this->variant()->stock);
    }

    public function test_expiry_request_losing_to_payment_fetches_and_records_the_capture(): void
    {
        $attempt = $this->attempt(true);
        $this->account();
        $this->transport->respond(['error' => ['type' => 'invalid_request_error', 'message' => 'Synthetic already-complete response.']], 400);
        $this->transport->respond($this->providerSession($attempt, true));
        $result = app(StripeSandboxPayments::class)->cancel($attempt);
        $this->assertSame('paid', $result->status);
        $this->assertSame(18, $this->variant()->stock);
        $this->assertSame(1, PaymentReceipt::query()->count());
        $this->assertSame('expire:'.$attempt->idempotency_key, $this->transport->header($this->transport->requests[1], 'Idempotency-Key'));
    }

    public function test_unavailable_expiry_and_unavailable_read_keep_the_hold(): void
    {
        $attempt = $this->attempt(true);
        $this->account();
        $this->transport->respond(new ApiConnectionException('Synthetic expiration timeout.'));
        $this->transport->respond(new ApiConnectionException('Synthetic retrieval timeout.'));
        $result = app(StripeSandboxPayments::class)->cancel($attempt);
        $this->assertSame('unknown', $result->status);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->assertSame(20, $this->variant()->stock);
        $this->assertSame(0, PaymentReceipt::query()->count());
    }

    public function test_verified_expiry_releases_after_a_successful_fresh_read(): void
    {
        $attempt = $this->attempt(true);
        $this->account();
        $this->transport->respond($this->providerSession($attempt, status: 'expired'));
        $this->transport->respond($this->providerSession($attempt, status: 'expired'));
        $result = app(StripeSandboxPayments::class)->cancel($attempt);
        $this->assertSame('expired', $result->status);
        $this->assertSame(0, StockAvailability::held($this->variant()->id));
        $this->assertSame(20, $this->variant()->stock);
        $this->assertSame(0, PaymentReceipt::query()->count());
    }

    #[DataProvider('invalidEvents')]
    public function test_wrong_webhook_scope_version_or_signature_never_enters_the_inbox(string $case): void
    {
        $attempt = $this->attempt();
        $event = $this->event($attempt);
        $timestamp = null;
        $signedBody = null;
        match ($case) {
            'live' => $event['livemode'] = true,
            'live-object' => $event['data']['object']['livemode'] = true,
            'connect' => $event['account'] = 'acct_otherMerchant',
            'context' => $event['context'] = 'acct_otherMerchant',
            'version' => $event['api_version'] = '2020-08-27',
            'old-signature' => $timestamp = time() - 600,
            'future-signature' => $timestamp = time() + 600,
            'altered-body' => $signedBody = '{"altered":true}',
        };
        $this->webhook($event, $timestamp, $signedBody)->assertStatus(400);
        $this->assertSame(0, PaymentEvent::query()->count());
        $this->assertSame(0, PaymentReceipt::query()->count());
        $this->assertSame([], $this->transport->requests);
    }

    public static function invalidEvents(): array
    {
        return array_map(fn (string $case) => [$case], ['live', 'live-object', 'connect', 'context', 'version',
            'old-signature', 'future-signature', 'altered-body']);
    }

    public function test_wrong_api_account_stops_before_session_creation_and_requires_reconciliation(): void
    {
        $attempt = $this->attempt();
        $this->account('acct_otherMerchant');
        $result = app(StripeSandboxPayments::class)->refresh($attempt);
        $this->assertSame('review', $result->status);
        $this->assertSame('provider-account-mismatch', $result->review_reason);
        $this->assertSame(1, count($this->transport->requests));
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
    }

    public function test_capture_in_another_currency_stays_in_the_actual_financial_currency(): void
    {
        $attempt = $this->attempt(true);
        $data = $this->providerSession($attempt, true);
        $data['payment_intent']['currency'] = 'usd';
        $data['payment_intent']['latest_charge']['currency'] = 'usd';
        $this->account();
        $this->transport->respond($data);
        app(StripeSandboxPayments::class)->refresh($attempt);
        $this->assertSame('review', $attempt->fresh()->status);
        $this->assertSame('USD', PaymentReceipt::query()->sole()->currency_code);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(20, $this->variant()->stock);
    }

    public function test_inconsistent_captured_payment_is_durable_but_never_fulfilled(): void
    {
        $attempt = $this->attempt(true);
        $data = $this->providerSession($attempt, true);
        $data['payment_intent']['amount_received']--;
        $this->account();
        $this->transport->respond($data);
        app(StripeSandboxPayments::class)->refresh($attempt);
        $this->assertSame('provider-capture-inconsistent', PaymentReceipt::query()->sole()->review_reason);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(20, $this->variant()->stock);
    }

    public function test_disabled_production_and_live_key_never_send_a_provider_request(): void
    {
        $attempt = $this->attempt();
        config(['payments.stripe_sandbox.secret_key' => StripeTestCredentials::secretKey('live')]);
        try {
            app(StripeSandboxPayments::class)->refresh($attempt);
            $this->fail('Live secret keys are not accepted.');
        } catch (\LogicException) {
            $this->assertSame([], $this->transport->requests);
        }
        $this->app->instance('env', 'production');
        $this->webhook($this->event($attempt))->assertNotFound();
        $this->artisan('oneshop:reconcile-stripe-sandbox')->assertSuccessful();
        $this->assertSame([], $this->transport->requests);
        $this->assertSame(0, PaymentEvent::query()->count());
    }

    public function test_gateway_refuses_networking_under_an_open_sql_transaction(): void
    {
        $attempt = $this->attempt(true);
        try {
            DB::transaction(fn () => app(StripeSandboxGateway::class)->retrieve($attempt));
            $this->fail('Provider networking must be outside locks.');
        } catch (\LogicException $error) {
            $this->assertSame('Stripe network calls must run outside SQL transactions.', $error->getMessage());
            $this->assertSame([], $this->transport->requests);
        }
    }

    public function test_an_already_paid_attempt_does_not_hide_a_failed_read_of_a_later_event(): void
    {
        $attempt = $this->attempt(true);
        $this->account();
        $this->transport->respond($this->providerSession($attempt, true));
        app(StripeSandboxPayments::class)->refresh($attempt);
        $this->webhook($this->event($attempt, 'evt_laterDelivery'))->assertOk();
        $event = PaymentEvent::query()->sole();
        $this->account();
        $this->transport->respond(new ApiConnectionException('Synthetic read outage after earlier success.'));
        $this->assertFalse(app(StripeSandboxPayments::class)->process($event));
        $this->assertSame('received', $event->fresh()->status);
        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame(18, $this->variant()->stock);
        $reversed = $this->providerSession($attempt, true);
        $reversed['payment_intent']['latest_charge']['refunded'] = true;
        $reversed['payment_intent']['latest_charge']['amount_refunded'] = $attempt->amount_minor;
        $this->account();
        $this->transport->respond($reversed);
        $this->assertTrue(app(StripeSandboxPayments::class)->process($event));
        $this->assertSame('review', $event->fresh()->status);
        $this->assertSame('review', $attempt->fresh()->status);
        $this->assertSame(1, PaymentReceipt::query()->count());
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(18, $this->variant()->stock);
    }
}
