<?php

use App\Checkout\DevelopmentCheckoutService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('oneshop.queue_via_scheduler')) {
    Schedule::command('queue:work', [
        'database',
        '--stop-when-empty',
        '--max-time' => 50,
        '--timeout' => 40,
        '--tries' => 3,
    ])->everyMinute()->withoutOverlapping(5);
}

Schedule::command('oneshop:expire-demo-checkouts')->everyMinute()->withoutOverlapping(5)
    ->when(fn () => DevelopmentCheckoutService::enabled());
