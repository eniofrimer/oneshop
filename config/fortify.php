<?php

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/account',
    'prefix' => 'auth',
    'domain' => null,
    'middleware' => ['web'],
    // Customer screens and actions will be implemented together in the customer milestone.
    'views' => false,
    'features' => [],
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
];
