<?php

namespace App\Payments;

use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;

class StripeSandboxPayments
{
    public function __construct(private StripeSandboxGateway $gateway, private PaymentLedger $ledger) {}

    public function refresh(PaymentAttempt $attempt): PaymentAttempt
    {
        try {
            return $this->refreshFromProvider($attempt);
        } catch (ProviderUnavailable $error) {
            return in_array($error->reason, ['creation-window-closed', 'provider-account-mismatch'], true)
                ? $this->ledger->requireReconciliation($attempt, $error->reason)
                : $this->ledger->markUnknown($attempt);
        } catch (\DomainException) {
            return $this->ledger->requireReconciliation($attempt, 'provider-session-mismatch');
        }
    }

    private function refreshFromProvider(PaymentAttempt $attempt): PaymentAttempt
    {
        $attempt->refresh();
        if ($attempt->provider_object_id === null) {
            if (in_array($attempt->status, ['paid', 'expired', 'review'], true)) {
                return $attempt;
            }
            $snapshot = $this->gateway->create($attempt);
            $this->ledger->bind($attempt, $snapshot);
        } else {
            $snapshot = $this->gateway->retrieve($attempt);
        }

        return $this->ledger->reconcile($attempt, $snapshot);
    }

    public function cancel(PaymentAttempt $attempt): PaymentAttempt
    {
        $attempt->refresh();
        if (in_array($attempt->status, ['paid', 'expired', 'review'], true)) {
            return $attempt;
        }
        if ($attempt->provider_object_id === null) {
            // Resolve creation using the original idempotency key before asking
            // the provider to expire it. No local-only release is permitted.
            $attempt = $this->refresh($attempt);
        }
        if ($attempt->provider_object_id === null || in_array($attempt->status, ['paid', 'expired', 'review'], true)) {
            return $attempt;
        }
        try {
            return $this->ledger->reconcile($attempt, $this->gateway->expire($attempt));
        } catch (ProviderUnavailable $error) {
            return $error->reason === 'provider-account-mismatch'
                ? $this->ledger->requireReconciliation($attempt, $error->reason) : $this->ledger->markUnknown($attempt);
        } catch (\DomainException) {
            return $this->ledger->requireReconciliation($attempt, 'provider-session-mismatch');
        }
    }

    public function process(PaymentEvent $event): bool
    {
        $event->refresh();
        if ($event->status !== 'received') {
            return $event->status === 'processed';
        }
        $attempt = PaymentAttempt::query()->where('provider', $event->provider)->where('provider_account', $event->provider_account)
            ->where('environment', $event->environment)->where('provider_object_id', $event->provider_object_id)->first();
        if (! $attempt) {
            $event->forceFill(['last_error_code' => 'session-not-bound', 'updated_at' => now()])->save();

            return false;
        }
        try {
            $attempt = $this->refreshFromProvider($attempt);
        } catch (ProviderUnavailable $error) {
            $this->ledger->markUnknown($attempt);
            $event->forceFill(['payment_attempt_id' => $attempt->id, 'last_error_code' => $error->reason, 'updated_at' => now()])->save();

            return false;
        } catch (\DomainException) {
            $this->ledger->requireReconciliation($attempt, 'provider-session-mismatch');
            $event->forceFill(['payment_attempt_id' => $attempt->id, 'status' => 'review',
                'last_error_code' => 'provider-session-mismatch', 'processed_at' => now()])->save();

            return true;
        }
        // A crash after settlement but before this marker is safe: replay runs
        // the same financial transition and capture identity again.
        $event->forceFill(['payment_attempt_id' => $attempt->id, 'status' => $attempt->status === 'review' ? 'review' : 'processed',
            'last_error_code' => $attempt->status === 'review' ? $attempt->review_reason : null, 'processed_at' => now()])->save();

        return true;
    }
}
