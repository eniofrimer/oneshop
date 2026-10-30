<?php

namespace App\Payments;

use App\Inventory\StockReservations;
use App\Models\DevelopmentCheckout;
use App\Models\PaymentAttempt;
use App\Models\PaymentOutbox;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Order;
use Lunar\Models\Transaction;

/**
 * Local test-payment state machine. Provider calls always happen outside this
 * class. Receipt, inventory, Lunar capture and outbox commit atomically.
 */
class PaymentLedger
{
    public function __construct(private StockReservations $reservations) {}

    public function prepare(Order $order, ProviderIdentity $identity, string $returnBaseUrl): PaymentAttempt
    {
        $this->guard();
        $url = parse_url($returnBaseUrl);
        if (! is_array($url) || ! in_array($url['scheme'] ?? null, ['http', 'https'], true)
            || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new \InvalidArgumentException('A fixed application return URL is required.');
        }

        return DB::transaction(function () use ($order, $identity, $returnBaseUrl): PaymentAttempt {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = PaymentAttempt::query()->where('order_id', $order->id)->first();
            if ($existing) {
                $this->matchIdentity($existing, $identity);

                return $existing;
            }
            $amount = (int) $order->getRawOriginal('total');
            if (! $order->placed_at || ($order->meta['oneshop_mode'] ?? null) !== 'stripe-test'
                || $amount < 50 || $amount > 2147483647 || $order->currency_code !== 'EUR'
                || DevelopmentCheckout::query()->where('order_id', $order->id)->exists()
                || Transaction::query()->where('order_id', $order->id)->where('type', 'capture')->where('success', true)->exists()) {
                throw new \LogicException('A new, explicitly prepared Stripe test order is required.');
            }
            $attempt = new PaymentAttempt;
            $attempt->forceFill(['id' => (string) Str::uuid(), 'order_id' => $order->id,
                'provider' => $identity->provider, 'provider_account' => $identity->account, 'environment' => $identity->environment,
                'amount_minor' => $amount, 'currency_code' => 'EUR', 'idempotency_key' => (string) Str::uuid(), 'status' => 'prepared']);
            $returnUrl = rtrim($returnBaseUrl, '/').'/checkout/stripe/'.$attempt->id.'/return';
            // Frozen parameters ensure retry never changes a provider request.
            // The single line is the already-taxed/shipped OneShop grand total.
            $attempt->creation_parameters = [
                'mode' => 'payment', 'client_reference_id' => $attempt->id,
                'payment_method_types' => ['card'], 'success_url' => $returnUrl, 'cancel_url' => $returnUrl,
                'automatic_tax' => ['enabled' => false], 'adaptive_pricing' => ['enabled' => false],
                'allow_promotion_codes' => false,
                'metadata' => ['oneshop_attempt' => $attempt->id],
                'payment_intent_data' => ['metadata' => ['oneshop_attempt' => $attempt->id]],
                'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => $amount,
                    'product_data' => ['name' => 'OneShop testna narudžba '.$order->reference]]]],
            ];
            $attempt->save();
            $this->reservations->reserve($attempt->id, $order, StockReservations::quantities($order));
            $order->update(['status' => 'payment-pending']);

            return $attempt;
        });
    }

    public function bind(PaymentAttempt $attempt, ProviderCheckoutSnapshot $snapshot): PaymentAttempt
    {
        $this->guard();

        return DB::transaction(function () use ($attempt, $snapshot): PaymentAttempt {
            Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $this->matchIdentity($attempt, $snapshot->identity);
            if ($snapshot->attemptId !== $attempt->id
                || ($attempt->provider_object_id !== null && $attempt->provider_object_id !== $snapshot->objectId)) {
                throw new \DomainException('Provider session belongs to another payment attempt.');
            }
            $attempt->forceFill(['provider_object_id' => $snapshot->objectId,
                'checkout_url' => in_array($attempt->status, ['paid', 'expired', 'review'], true) ? null : $snapshot->checkoutUrl])->save();

            return $attempt;
        });
    }

    public function markUnknown(PaymentAttempt $attempt): PaymentAttempt
    {
        $this->guard();

        return DB::transaction(function () use ($attempt): PaymentAttempt {
            Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (! in_array($attempt->status, ['paid', 'expired', 'review'], true)) {
                $attempt->forceFill(['status' => 'unknown', 'last_checked_at' => now()])->save();
            }

            return $attempt;
        });
    }

    public function requireReconciliation(PaymentAttempt $attempt, string $reason): PaymentAttempt
    {
        $this->guard();
        if (! in_array($reason, ['creation-window-closed', 'provider-account-mismatch', 'provider-session-mismatch'], true)) {
            throw new \InvalidArgumentException('Unsupported reconciliation reason.');
        }

        return DB::transaction(function () use ($attempt, $reason): PaymentAttempt {
            $order = Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (! in_array($attempt->status, ['paid', 'expired', 'review'], true)) {
                $attempt->forceFill(['status' => 'review', 'review_reason' => $reason, 'checkout_url' => null])->save();
                $order->update(['status' => 'payment-review']);
            }

            return $attempt;
        });
    }

    public function reconcile(PaymentAttempt $attempt, ProviderCheckoutSnapshot $snapshot): PaymentAttempt
    {
        $this->guard();

        return DB::transaction(function () use ($attempt, $snapshot): PaymentAttempt {
            // Every transition uses the same order -> attempt -> SKU lock order.
            $order = Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $this->matchIdentity($attempt, $snapshot->identity);
            if ($snapshot->attemptId !== $attempt->id || $snapshot->objectId !== $attempt->provider_object_id) {
                throw new \DomainException('Unbound provider session.');
            }
            $attempt->forceFill(['last_checked_at' => now()])->save();
            if ($snapshot->capture !== null) {
                return $this->capture($attempt, $order, $snapshot);
            }
            if (in_array($attempt->status, ['paid', 'expired', 'review'], true)) {
                return $attempt;
            }
            if ($snapshot->status === 'expired') {
                $this->reservations->release($attempt->id, $order, StockReservations::quantities($order), 'provider-expired');
                $attempt->forceFill(['status' => 'expired', 'checkout_url' => null])->save();
                $order->update(['status' => 'payment-expired']);
            } else {
                // Complete-but-unpaid is not success and does not release stock.
                $attempt->forceFill(['status' => 'pending'])->save();
            }

            return $attempt;
        });
    }

    private function capture(PaymentAttempt $attempt, Order $order, ProviderCheckoutSnapshot $snapshot): PaymentAttempt
    {
        $capture = $snapshot->capture;
        if (! $capture) {
            throw new \LogicException('Expected an authenticated capture.');
        }
        $receipt = PaymentReceipt::query()->where('provider', $attempt->provider)->where('provider_account', $attempt->provider_account)
            ->where('environment', $attempt->environment)->where('capture_reference', $capture->captureReference)->lockForUpdate()->first();
        if ($receipt) {
            if ($receipt->payment_attempt_id !== $attempt->id || $receipt->amount_minor !== $capture->amountMinor
                || $receipt->currency_code !== $capture->currency || $receipt->payment_reference !== $capture->paymentReference) {
                throw new \DomainException('Conflicting confirmed capture.');
            }
            if ($capture->reversed) {
                $this->review($attempt, $order, $receipt, 'provider-reversal-observed');
            }

            return $attempt;
        }
        $receipt = new PaymentReceipt;
        $receipt->forceFill(['payment_attempt_id' => $attempt->id, 'provider' => $attempt->provider,
            'provider_account' => $attempt->provider_account, 'environment' => $attempt->environment,
            'capture_reference' => $capture->captureReference, 'payment_reference' => $capture->paymentReference,
            'amount_minor' => $capture->amountMinor, 'currency_code' => $capture->currency, 'allocation_status' => 'pending'])->save();

        $reason = match (true) {
            $capture->reversed => 'provider-reversal-observed',
            $capture->inconsistent => 'provider-capture-inconsistent',
            $snapshot->status !== 'complete' => 'session-state-mismatch',
            $capture->amountMinor !== $attempt->amount_minor => 'capture-amount-mismatch',
            $capture->currency !== $attempt->currency_code => 'capture-currency-mismatch',
            $snapshot->amountMinor !== $attempt->amount_minor || $snapshot->currency !== $attempt->currency_code => 'session-total-mismatch',
            (int) $order->getRawOriginal('total') !== $attempt->amount_minor || $order->currency_code !== $attempt->currency_code => 'order-snapshot-mismatch',
            ($order->meta['oneshop_mode'] ?? null) !== 'stripe-test' => 'order-mode-mismatch',
            in_array($attempt->status, ['expired', 'review', 'paid'], true) => 'capture-after-terminal-state',
            Transaction::query()->where('order_id', $order->id)->where('type', 'capture')->where('success', true)->exists() => 'order-already-captured',
            default => null,
        };
        if ($reason === null) {
            try {
                // A savepoint rolls back all inventory changes on a domain
                // failure while preserving the independently confirmed receipt.
                $this->reservations->consume($attempt->id, $order, StockReservations::quantities($order));
            } catch (ValidationException|\LogicException) {
                $reason = 'inventory-allocation-failed';
            }
        }
        if ($reason !== null) {
            $this->review($attempt, $order, $receipt, $reason);
        } else {
            $transaction = Transaction::query()->create(['order_id' => $order->id, 'type' => 'capture', 'success' => true,
                'driver' => 'oneshop-stripe-test', 'amount' => $capture->amountMinor, 'reference' => $capture->captureReference,
                'status' => 'succeeded', 'card_type' => null, 'last_four' => null, 'notes' => null,
                'meta' => ['mode' => 'test', 'payment_attempt_id' => $attempt->id]]);
            $receipt->forceFill(['allocation_status' => 'allocated', 'transaction_id' => $transaction->id])->save();
            $attempt->forceFill(['status' => 'paid', 'settled_at' => now(), 'checkout_url' => null])->save();
            $order->update(['status' => 'payment-received']);
        }
        $this->outbox($receipt, 'payment.captured');

        return $attempt;
    }

    private function review(PaymentAttempt $attempt, Order $order, PaymentReceipt $receipt, string $reason): void
    {
        $receipt->forceFill(['allocation_status' => 'review', 'review_reason' => $reason])->save();
        $attempt->forceFill(['status' => 'review', 'review_reason' => $reason, 'checkout_url' => null])->save();
        $order->update(['status' => 'payment-review']);
        $this->outbox($receipt, 'payment.review-required');
    }

    private function outbox(PaymentReceipt $receipt, string $topic): void
    {
        $key = $topic.':'.$receipt->id;
        // The order and attempt locks serialize all receipts for this attempt.
        if (PaymentOutbox::query()->where('event_key', $key)->exists()) {
            return;
        }
        (new PaymentOutbox)->forceFill(['id' => (string) Str::uuid(), 'event_key' => $key, 'topic' => $topic,
            'payment_receipt_id' => $receipt->id, 'payload' => ['receipt_id' => $receipt->id,
                'payment_attempt_id' => $receipt->payment_attempt_id, 'amount_minor' => $receipt->amount_minor,
                'currency_code' => $receipt->currency_code, 'allocation_status' => $receipt->allocation_status]])->save();
    }

    private function matchIdentity(PaymentAttempt $attempt, ProviderIdentity $identity): void
    {
        if ($attempt->provider !== $identity->provider || $attempt->provider_account !== $identity->account || $attempt->environment !== $identity->environment) {
            throw new \DomainException('Payment provider scope mismatch.');
        }
    }

    private function guard(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \LogicException('External payments are not enabled for production.');
        }
    }
}
