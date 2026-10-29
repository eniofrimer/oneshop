<?php

return [
    'primary_locale' => 'hr',
    // The final set of storefront languages is a product decision, not auto-enabled here.
    'enabled_locales' => ['hr'],
    'currency' => 'EUR',
    // Search is a later milestone. Managed databases need no extension DDL by default.
    'search_extensions' => env('ONESHOP_SEARCH_EXTENSIONS', false),
    // Simulator is additionally blocked outside local/testing by the service.
    'demo_checkout' => env('ONESHOP_DEMO_CHECKOUT', false),
    // Shared hosting drains database jobs through schedule:run, without a daemon.
    'queue_via_scheduler' => env('ONESHOP_QUEUE_VIA_SCHEDULER', false),
];
