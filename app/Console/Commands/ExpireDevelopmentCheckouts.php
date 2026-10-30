<?php

namespace App\Console\Commands;

use App\Checkout\DevelopmentCheckoutService;
use Illuminate\Console\Command;

class ExpireDevelopmentCheckouts extends Command
{
    protected $signature = 'oneshop:expire-demo-checkouts {--limit=100 : Maximum number of expired simulators, 1–1000}';

    protected $description = 'Release expired local/testing simulator holds; never processes external payment attempts';

    public function handle(DevelopmentCheckoutService $checkout): int
    {
        if (! DevelopmentCheckoutService::enabled()) {
            $this->line('Development checkout is disabled; no reservations were changed.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be an integer from 1 to 1000.');

            return self::FAILURE;
        }
        $this->info('Expired development checkouts: '.$checkout->expirePending($limit));

        return self::SUCCESS;
    }
}
