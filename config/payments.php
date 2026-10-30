<?php

return [
    // Live payments and production enabling are deliberately not implemented.
    'stripe_sandbox' => [
        'enabled' => env('ONESHOP_STRIPE_SANDBOX', false),
        'account' => env('STRIPE_TEST_ACCOUNT_ID'),
        'secret_key' => env('STRIPE_TEST_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
    ],
];
