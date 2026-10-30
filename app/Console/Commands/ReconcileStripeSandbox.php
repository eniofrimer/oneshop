<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Payments\StripeSandboxConfiguration;
use App\Payments\StripeSandboxPayments;
use Illuminate\Console\Command;

class ReconcileStripeSandbox extends Command
{
    protected $signature = 'oneshop:reconcile-stripe-sandbox {--limit=20 : Maximum records per inbox/attempt batch (1-100)}';

    protected $description = 'Reconcile durable Stripe test events and unknown/pending attempts without releasing on local timeout';

    public function handle(StripeSandboxPayments $payments, StripeSandboxConfiguration $configuration): int
    {
        if (! StripeSandboxConfiguration::enabled()) {
            $this->info('Stripe sandbox is disabled.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Limit must be an integer between 1 and 100.');

            return self::FAILURE;
        }
        try {
            $identity = $configuration->identity();
            $configuration->key();
            $configuration->webhookSecret();
        } catch (\LogicException) {
            $this->error('Stripe sandbox credentials are incomplete or invalid.');

            return self::FAILURE;
        }
        $deadline = microtime(true) + 45;
        $events = PaymentEvent::query()->where('provider', $identity->provider)->where('provider_account', $identity->account)
            ->where('environment', $identity->environment)->where('status', 'received')->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        foreach ($events as $event) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $payments->process($event);
        }
        $attempts = PaymentAttempt::query()->where('provider', $identity->provider)->where('provider_account', $identity->account)
            ->where('environment', $identity->environment)->whereIn('status', ['prepared', 'pending', 'unknown'])
            ->where('updated_at', '<=', now()->subMinute())->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        foreach ($attempts as $attempt) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $payments->refresh($attempt);
        }
        $this->info('Stripe sandbox reconciliation batch completed.');

        return self::SUCCESS;
    }
}
