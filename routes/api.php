<?php

use App\Http\Controllers\StripeSandboxWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/stripe/sandbox', StripeSandboxWebhookController::class)
    ->middleware('throttle:120,1')->name('stripe.sandbox.webhook');
