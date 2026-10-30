<?php

namespace Tests\Feature;

use App\Inventory\StockAvailability;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\PaymentOutbox;
use App\Models\PaymentReceipt;
use App\Models\StockReservation;
use App\Payments\CapturedPayment;
use App\Payments\PaymentEventInbox;
use App\Payments\PaymentLedger;
use App\Payments\ProviderCheckoutSnapshot;
use App\Payments\ProviderIdentity;
use App\Payments\VerifiedProviderEvent;
use App\Storefront\GuestCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Cart;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Synthetic authenticated-provider snapshots; these tests make no Stripe calls. */
class PaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US', 'oneshop.demo_checkout' => true]);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
    }

    private function variant(): ProductVariant
    {
        return ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
    }

    private function order(string $mode = 'stripe-test'): Order
    {
        // Reuse address validation only; never convert an existing demo order.
        $this->post(route('store.cart.add'), ['variant_id' => $this->variant()->id, 'quantity' => 2])->assertRedirect();
        $this->post(route('store.checkout.address'), [
            'first_name' => 'Ana', 'last_name' => 'Provider', 'contact_email' => 'payments@example.test',
            'contact_phone' => '+385 91 000 0000', 'line_one' => 'Testna ulica 1',
            'city' => 'Zagreb', 'postcode' => '10000', 'country_id' => Country::query()->where('iso2', 'HR')->sole()->id,
        ])->assertRedirect();
        $cart = Cart::query()->findOrFail(session(GuestCart::SESSION_KEY));
        foreach ($cart->lines as $line) {
            $line->update(['meta' => ['oneshop_track_stock' => true]]);
        }
        $cart->refresh()->update(['meta' => ['oneshop_mode' => $mode]]);
        $order = $cart->createOrder();
        $order->update(['placed_at' => now()]);
        $cart->update(['completed_at' => now()]);

        return $order;
    }

    private function prepare(): PaymentAttempt
    {
        return app(PaymentLedger::class)->prepare($this->order(), new ProviderIdentity('acct_testMerchant'), 'http://shop.example.test');
    }

    private function snapshot(PaymentAttempt $attempt, ?CapturedPayment $capture = null, string $status = 'open'): ProviderCheckoutSnapshot
    {
        return new ProviderCheckoutSnapshot(new ProviderIdentity('acct_testMerchant'), 'cs_test_oneSession', $attempt->id,
            $status, $attempt->amount_minor, 'EUR', $capture, $status === 'open' ? 'https://checkout.stripe.com/c/pay/test' : null);
    }

    private function captured(PaymentAttempt $attempt): ProviderCheckoutSnapshot
    {
        return $this->snapshot($attempt, new CapturedPayment('ch_testCapture', 'pi_testIntent', $attempt->amount_minor, 'EUR'), 'complete');
    }

    public function test_preparation_freezes_one_encrypted_request_and_one_hold_per_order(): void
    {
        $attempt = $this->prepare();
        $again = app(PaymentLedger::class)->prepare($attempt->order, new ProviderIdentity('acct_testMerchant'), 'http://changed.example.test');
        $this->assertSame($attempt->id, $again->id);
        $this->assertSame($attempt->idempotency_key, $again->idempotency_key);
        $this->assertSame($attempt->creation_parameters, $again->creation_parameters);
        $this->assertStringStartsWith('http://shop.example.test/', $again->creation_parameters['success_url']);
        $this->assertSame($again->creation_parameters['success_url'], $again->creation_parameters['cancel_url']);
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->assertSame(20, $this->variant()->stock);
        $this->assertStringNotContainsString('shop.example.test', DB::table('payment_attempts')->sole()->creation_parameters);
        $this->assertArrayNotHasKey('creation_parameters', $attempt->toArray());
        $this->assertArrayNotHasKey('checkout_url', $attempt->toArray());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, PaymentOutbox::query()->count());
    }

    public function test_a_demo_order_is_not_promoted_to_a_provider_payment(): void
    {
        $order = $this->order('demo');
        try {
            app(PaymentLedger::class)->prepare($order, new ProviderIdentity('acct_testMerchant'), 'http://shop.example.test');
            $this->fail('Demo orders must never become provider requests.');
        } catch (\LogicException) {
            $this->assertSame(0, PaymentAttempt::query()->count());
            $this->assertSame(0, StockReservation::query()->count());
        }
    }

    public function test_timeout_keeps_inventory_and_cannot_be_bypassed_with_a_second_attempt(): void
    {
        $attempt = $this->prepare();
        $ledger = app(PaymentLedger::class);
        $ledger->markUnknown($attempt);
        $again = $ledger->prepare($attempt->order, new ProviderIdentity('acct_testMerchant'), 'http://shop.example.test');
        $this->assertSame('unknown', $again->status);
        $this->assertSame($attempt->id, $again->id);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->assertSame(0, PaymentReceipt::query()->count());
        $this->assertSame(20, $this->variant()->stock);
    }

    public function test_duplicate_captures_and_old_pending_snapshots_never_consume_twice_or_regress(): void
    {
        $attempt = $this->prepare();
        $ledger = app(PaymentLedger::class);
        $ledger->bind($attempt, $this->snapshot($attempt));
        $ledger->reconcile($attempt, $this->captured($attempt));
        $ledger->reconcile($attempt, $this->captured($attempt));
        $ledger->reconcile($attempt, $this->snapshot($attempt));
        $ledger->markUnknown($attempt);
        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame('payment-received', $attempt->order->fresh()->status);
        $this->assertSame(18, $this->variant()->stock);
        $this->assertSame(0, StockAvailability::held($this->variant()->id));
        $this->assertSame(StockReservation::CONSUMED, StockReservation::query()->sole()->state);
        $this->assertSame(1, Transaction::query()->count());
        $receipt = PaymentReceipt::query()->sole();
        $this->assertSame('allocated', $receipt->allocation_status);
        $this->assertSame(Transaction::query()->sole()->id, $receipt->transaction_id);
        $this->assertSame(1, PaymentOutbox::query()->count());
        $this->assertSame('payment.captured', PaymentOutbox::query()->sole()->topic);
        $this->assertSame($attempt->amount_minor, PaymentOutbox::query()->sole()->payload['amount_minor']);
    }

    public function test_only_current_confirmed_expiry_releases_a_provider_hold(): void
    {
        $attempt = $this->prepare();
        $ledger = app(PaymentLedger::class);
        $ledger->bind($attempt, $this->snapshot($attempt));
        $this->travel(2)->days();
        $ledger->markUnknown($attempt);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $ledger->reconcile($attempt, $this->snapshot($attempt, status: 'complete'));
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $ledger->reconcile($attempt, $this->snapshot($attempt, status: 'expired'));
        $ledger->reconcile($attempt, $this->snapshot($attempt, status: 'expired'));
        $this->assertSame('expired', $attempt->fresh()->status);
        $this->assertSame('provider-expired', StockReservation::query()->sole()->release_reason);
        $this->assertSame(20, $this->variant()->stock);
        $this->assertSame(0, StockAvailability::held($this->variant()->id));
        $this->assertSame(0, PaymentReceipt::query()->count());
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_late_capture_after_release_remains_a_real_receipt_without_automatic_fulfillment(): void
    {
        $attempt = $this->prepare();
        $ledger = app(PaymentLedger::class);
        $ledger->bind($attempt, $this->snapshot($attempt));
        $ledger->reconcile($attempt, $this->snapshot($attempt, status: 'expired'));
        $ledger->reconcile($attempt, $this->captured($attempt));
        $ledger->reconcile($attempt, $this->captured($attempt));
        $this->assertReview($attempt, 'capture-after-terminal-state');
        $this->assertSame(StockReservation::RELEASED, StockReservation::query()->sole()->state);
        $this->assertSame(0, StockAvailability::held($this->variant()->id));
    }

    public function test_missing_reserved_inventory_does_not_erase_a_confirmed_financial_fact(): void
    {
        $attempt = $this->prepare();
        $ledger = app(PaymentLedger::class);
        $ledger->bind($attempt, $this->snapshot($attempt));
        // Simulate an external stock corruption outside the protected admin.
        DB::table((new ProductVariant)->getTable())->where('id', $this->variant()->id)->update(['stock' => 0]);
        $ledger->reconcile($attempt, $this->captured($attempt));
        $this->assertSame('inventory-allocation-failed', PaymentReceipt::query()->sole()->review_reason);
        $this->assertSame('review', $attempt->fresh()->status);
        $this->assertSame(0, $this->variant()->stock);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(2, PaymentOutbox::query()->count());
    }

    public function test_foreign_currency_capture_is_recorded_in_its_own_currency_without_a_mislabelled_lunar_transaction(): void
    {
        $attempt = $this->prepare();
        $ledger = app(PaymentLedger::class);
        $ledger->bind($attempt, $this->snapshot($attempt));
        $ledger->reconcile($attempt, $this->snapshot($attempt, new CapturedPayment('ch_testCapture', 'pi_testIntent', $attempt->amount_minor, 'USD'), 'complete'));
        $this->assertReview($attempt, 'capture-currency-mismatch');
        $this->assertSame('USD', PaymentReceipt::query()->sole()->currency_code);
        $this->assertSame('EUR', $attempt->order->fresh()->currency_code);
    }

    public function test_incorrect_amount_requires_review_without_allocating(): void
    {
        $attempt = $this->prepare();
        $ledger = app(PaymentLedger::class);
        $ledger->bind($attempt, $this->snapshot($attempt));
        $snapshot = $this->snapshot($attempt, new CapturedPayment('ch_testCapture', 'pi_testIntent', $attempt->amount_minor - 1, 'EUR'), 'complete');
        $ledger->reconcile($attempt, $snapshot);
        $this->assertReview($attempt, 'capture-amount-mismatch');
    }

    public function test_reversal_observed_after_a_capture_is_visible_and_does_not_automatically_return_stock(): void
    {
        $attempt = $this->prepare();
        $ledger = app(PaymentLedger::class);
        $ledger->bind($attempt, $this->snapshot($attempt));
        $ledger->reconcile($attempt, $this->captured($attempt));
        $reversed = $this->snapshot($attempt, new CapturedPayment('ch_testCapture', 'pi_testIntent', $attempt->amount_minor, 'EUR', true), 'complete');
        $ledger->reconcile($attempt, $reversed);
        $ledger->reconcile($attempt, $reversed);
        $ledger->reconcile($attempt, $this->captured($attempt));
        $this->assertSame('review', $attempt->fresh()->status);
        $this->assertSame('provider-reversal-observed', PaymentReceipt::query()->sole()->review_reason);
        $this->assertSame(18, $this->variant()->stock);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(2, PaymentOutbox::query()->count());
        $this->assertSame('allocated', PaymentOutbox::query()->where('topic', 'payment.captured')->sole()->payload['allocation_status']);
    }

    public function test_database_failure_rolls_back_receipt_stock_transaction_and_outbox_then_retry_succeeds(): void
    {
        $attempt = $this->prepare();
        $ledger = app(PaymentLedger::class);
        $ledger->bind($attempt, $this->snapshot($attempt));
        $fail = true;
        PaymentOutbox::creating(function () use (&$fail): void {
            if ($fail) {
                throw new \RuntimeException('Synthetic outbox failure.');
            }
        });
        try {
            $ledger->reconcile($attempt, $this->captured($attempt));
            $this->fail('Expected the synthetic write failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic outbox failure.', $error->getMessage());
            $this->assertSame(20, $this->variant()->stock);
            $this->assertSame(2, StockAvailability::held($this->variant()->id));
            $this->assertSame(0, PaymentReceipt::query()->count());
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, PaymentOutbox::query()->count());
        }
        $fail = false;
        $ledger->reconcile($attempt, $this->captured($attempt));
        $this->assertSame(18, $this->variant()->stock);
        $this->assertSame(1, PaymentReceipt::query()->count());
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, PaymentOutbox::query()->count());
    }

    public function test_scope_and_session_binding_reject_foreign_or_unbound_confirmations(): void
    {
        $attempt = $this->prepare();
        $ledger = app(PaymentLedger::class);
        foreach ([
            $this->captured($attempt),
            new ProviderCheckoutSnapshot(new ProviderIdentity('acct_otherMerchant'), 'cs_test_oneSession', $attempt->id, 'complete',
                $attempt->amount_minor, 'EUR', new CapturedPayment('ch_testCapture', 'pi_testIntent', $attempt->amount_minor, 'EUR')),
        ] as $snapshot) {
            try {
                $ledger->reconcile($attempt, $snapshot);
                $this->fail('Expected an unbound/scope rejection.');
            } catch (\DomainException) {
                $this->assertSame(0, PaymentReceipt::query()->count());
                $this->assertSame(2, StockAvailability::held($this->variant()->id));
            }
        }
        $ledger->bind($attempt, $this->snapshot($attempt));
        try {
            $ledger->bind($attempt, new ProviderCheckoutSnapshot(new ProviderIdentity('acct_testMerchant'), 'cs_test_changedSession',
                $attempt->id, 'open', $attempt->amount_minor, 'EUR'));
            $this->fail('An attempt cannot be rebound.');
        } catch (\DomainException) {
            $this->assertSame('cs_test_oneSession', $attempt->fresh()->provider_object_id);
        }
    }

    public function test_delivery_deduplication_and_capture_deduplication_are_independent(): void
    {
        $attempt = $this->prepare();
        $inbox = app(PaymentEventInbox::class);
        $identity = new ProviderIdentity('acct_testMerchant');
        $one = new VerifiedProviderEvent($identity, 'evt_oneDelivery', 'cs_test_oneSession', 'checkout.session.completed', hash('sha256', 'one'));
        $two = new VerifiedProviderEvent($identity, 'evt_otherDelivery', 'cs_test_oneSession', 'checkout.session.async_payment_succeeded', hash('sha256', 'two'));
        $first = $inbox->record($one);
        $this->assertSame($first->id, $inbox->record($one)->id);
        $inbox->record($two);
        $this->assertSame(2, PaymentEvent::query()->count());
        $ledger = app(PaymentLedger::class);
        $ledger->bind($attempt, $this->snapshot($attempt));
        foreach ([$one, $two] as $event) {
            $ledger->reconcile($attempt, $this->captured($attempt));
        }
        $this->assertSame(1, PaymentReceipt::query()->count());
        $this->assertSame(1, PaymentOutbox::query()->count());
        $this->assertSame(18, $this->variant()->stock);
        try {
            $inbox->record(new VerifiedProviderEvent($identity, 'evt_oneDelivery', 'cs_test_oneSession', 'checkout.session.completed', hash('sha256', 'changed')));
            $this->fail('A duplicate identity cannot overwrite its body fingerprint.');
        } catch (\DomainException) {
            $this->assertSame(hash('sha256', 'one'), $first->fresh()->body_sha256);
        }
    }

    #[DataProvider('immutableAttemptFields')]
    public function test_attempt_snapshot_cannot_be_rewritten(string $field, mixed $value): void
    {
        $attempt = $this->prepare();
        $this->expectException(\LogicException::class);
        $attempt->forceFill([$field => $value])->save();
    }

    public static function immutableAttemptFields(): array
    {
        return [['amount_minor', 1], ['currency_code', 'USD'], ['provider_account', 'acct_otherMerchant'],
            ['idempotency_key', '00000000-0000-4000-8000-000000000000'], ['creation_parameters', ['changed' => true]]];
    }

    public function test_production_and_live_scope_are_rejected_before_preparing_any_payment(): void
    {
        try {
            new ProviderIdentity('acct_testMerchant', environment: 'live');
            $this->fail('Live mode is not implemented.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, PaymentAttempt::query()->count());
        }
        $order = $this->order();
        $this->app->instance('env', 'production');
        $this->expectException(\LogicException::class);
        app(PaymentLedger::class)->prepare($order, new ProviderIdentity('acct_testMerchant'), 'http://shop.example.test');
    }

    private function assertReview(PaymentAttempt $attempt, string $reason): void
    {
        $this->assertSame('review', $attempt->fresh()->status);
        $this->assertSame('payment-review', $attempt->order->fresh()->status);
        $receipt = PaymentReceipt::query()->sole();
        $this->assertSame('review', $receipt->allocation_status);
        $this->assertSame($reason, $receipt->review_reason);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(20, $this->variant()->stock);
        $this->assertSame(2, PaymentOutbox::query()->count());
    }
}
