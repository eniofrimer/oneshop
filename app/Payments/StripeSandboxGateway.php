<?php

namespace App\Payments;

use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeSandboxGateway
{
    private ?StripeClient $stripe = null;

    private bool $accountVerified = false;

    public function __construct(private StripeSandboxConfiguration $configuration) {}

    public function identity(): ProviderIdentity
    {
        return $this->configuration->identity();
    }

    public function create(PaymentAttempt $attempt): ProviderCheckoutSnapshot
    {
        $this->matchAttempt($attempt);
        if ($attempt->created_at->lessThanOrEqualTo(now()->subHours(23))) {
            // Stripe may prune V1 idempotency keys after at least 24 hours.
            // This technical retry bound is not a stock-hold expiry policy.
            throw new ProviderUnavailable('creation-window-closed');
        }
        try {
            $session = $this->client()->checkout->sessions->create($attempt->creation_parameters,
                ['idempotency_key' => $attempt->idempotency_key]);

            return $this->snapshot($session);
        } catch (ApiErrorException|UnexpectedValueException) {
            throw new ProviderUnavailable;
        }
    }

    public function retrieve(PaymentAttempt $attempt): ProviderCheckoutSnapshot
    {
        $this->matchAttempt($attempt);
        if (! is_string($attempt->provider_object_id)) {
            throw new \LogicException('A bound Stripe session is required.');
        }
        try {
            return $this->snapshot($this->client()->checkout->sessions->retrieve($attempt->provider_object_id,
                ['expand' => ['payment_intent.latest_charge']]));
        } catch (ApiErrorException|UnexpectedValueException) {
            throw new ProviderUnavailable;
        }
    }

    public function expire(PaymentAttempt $attempt): ProviderCheckoutSnapshot
    {
        $this->matchAttempt($attempt);
        if (! is_string($attempt->provider_object_id)) {
            throw new \LogicException('A bound Stripe session is required.');
        }
        try {
            $this->client()->checkout->sessions->expire($attempt->provider_object_id, [],
                ['idempotency_key' => 'expire:'.$attempt->idempotency_key]);
        } catch (ApiErrorException|UnexpectedValueException) {
            // A concurrent payment/expiry may have won; only a successful fresh
            // retrieval can decide whether to consume or release the hold.
        }

        return $this->retrieve($attempt);
    }

    public function verifyEvent(string $body, string $signature): ?VerifiedProviderEvent
    {
        $identity = $this->identity();
        $event = Webhook::constructEvent($body, $signature, $this->configuration->webhookSecret());
        $values = $event->toArray();
        if (($values['livemode'] ?? null) !== false
            || ($values['api_version'] ?? null) !== StripeSandboxConfiguration::API_VERSION
            || ! empty($values['account']) || ! empty($values['context'])) {
            throw new \DomainException('Unsupported Stripe webhook scope or API version.');
        }
        if (! in_array($values['type'] ?? null, VerifiedProviderEvent::TYPES, true)) {
            return null;
        }
        $object = $values['data']['object'] ?? null;
        if (! is_array($object) || ($object['object'] ?? null) !== 'checkout.session' || ($object['livemode'] ?? null) !== false
            || ! is_string($object['id'] ?? null) || ! is_string($values['id'] ?? null)) {
            throw new \DomainException('Invalid Stripe event object.');
        }

        return new VerifiedProviderEvent($identity, $values['id'], $object['id'], $values['type'], hash('sha256', $body));
    }

    private function matchAttempt(PaymentAttempt $attempt): void
    {
        $identity = $this->identity();
        if ($attempt->provider !== $identity->provider || $attempt->provider_account !== $identity->account || $attempt->environment !== $identity->environment) {
            throw new \DomainException('Stripe attempt scope mismatch.');
        }
    }

    private function client(): StripeClient
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Stripe network calls must run outside SQL transactions.');
        }
        if ($this->stripe === null) {
            // The installed SDK uses a shared HTTP client. Change only its
            // timeout settings; no endpoint override or disabled TLS checking.
            CurlClient::instance()->setConnectTimeout(5)->setTimeout(15);
            $this->stripe = new StripeClient(['api_key' => $this->configuration->key(),
                'stripe_version' => StripeSandboxConfiguration::API_VERSION, 'max_network_retries' => 0]);
        }
        if (! $this->accountVerified) {
            $account = $this->stripe->accounts->retrieve();
            if ($account->id !== $this->identity()->account) {
                throw new ProviderUnavailable('provider-account-mismatch');
            }
            $this->accountVerified = true;
        }

        return $this->stripe;
    }

    private function snapshot(Session $session): ProviderCheckoutSnapshot
    {
        $data = $session->toArray();
        if (($data['object'] ?? null) !== 'checkout.session' || ($data['livemode'] ?? null) !== false
            || ($data['mode'] ?? null) !== 'payment' || ! is_string($data['id'] ?? null)
            || ! is_string($data['client_reference_id'] ?? null) || ! is_string($data['status'] ?? null)
            || ! is_int($data['amount_total'] ?? null) || ! is_string($data['currency'] ?? null)
            || ! in_array($data['payment_status'] ?? null, ['unpaid', 'paid', 'no_payment_required'], true)) {
            throw new ProviderUnavailable('provider-response-invalid');
        }
        $capture = null;
        if (($data['payment_intent'] ?? null) !== null) {
            $intent = $data['payment_intent'];
            if (is_string($intent) && preg_match('/\Api_[A-Za-z0-9]{1,120}\z/D', $intent)) {
                $intent = $this->client()->paymentIntents->retrieve($intent, ['expand' => ['latest_charge']])->toArray();
            }
            if (! is_array($intent) || ($intent['object'] ?? null) !== 'payment_intent'
                || ($intent['livemode'] ?? null) !== false || ! is_string($intent['id'] ?? null)) {
                throw new ProviderUnavailable('provider-response-invalid');
            }
            $charge = $intent['latest_charge'] ?? null;
            if (is_string($charge) && preg_match('/\Ach_[A-Za-z0-9]{1,120}\z/D', $charge)) {
                $charge = $this->client()->charges->retrieve($charge)->toArray();
            }
            if (is_array($charge) && ($charge['paid'] ?? null) === true && ($charge['captured'] ?? null) === true) {
                if (($charge['object'] ?? null) !== 'charge' || ($charge['livemode'] ?? null) !== false
                    || ! is_string($charge['id'] ?? null) || ($charge['payment_intent'] ?? null) !== $intent['id']
                    || ! is_int($charge['amount_captured'] ?? null) || ! is_string($charge['currency'] ?? null)) {
                    throw new ProviderUnavailable('provider-response-invalid');
                }
                try {
                    $capture = new CapturedPayment($charge['id'], $intent['id'], $charge['amount_captured'], strtoupper($charge['currency']),
                        ($charge['refunded'] ?? false) === true || ($charge['amount_refunded'] ?? 0) > 0 || ($charge['disputed'] ?? false) === true,
                        ($intent['status'] ?? null) !== 'succeeded' || ($intent['amount_received'] ?? null) !== $charge['amount_captured']
                            || ($intent['currency'] ?? null) !== $charge['currency'] || $data['payment_status'] !== 'paid'
                            || ($intent['metadata']['oneshop_attempt'] ?? null) !== $data['client_reference_id']);
                } catch (\InvalidArgumentException) {
                    throw new ProviderUnavailable('provider-response-invalid');
                }
            }
        }
        if ($data['payment_status'] === 'paid' && $capture === null) {
            throw new ProviderUnavailable('provider-capture-unconfirmed');
        }
        $url = $data['url'] ?? null;
        if ($url !== null && ! is_string($url)) {
            throw new ProviderUnavailable('provider-response-invalid');
        }
        try {
            return new ProviderCheckoutSnapshot($this->identity(), $data['id'], $data['client_reference_id'], $data['status'],
                $data['amount_total'], strtoupper($data['currency']), $capture, $url);
        } catch (\InvalidArgumentException) {
            throw new ProviderUnavailable('provider-response-invalid');
        }
    }
}
