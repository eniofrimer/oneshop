<?php

return [
    'multi_factor_authentication' => ['label' => 'Dvofaktorska provjera (2FA)'],
    'form' => [
        'password' => ['validation_attribute' => 'lozinka'],
        'password_confirmation' => ['validation_attribute' => 'potvrda lozinke'],
        'current_password' => [
            'label' => 'Trenutačna lozinka',
            'below_content' => 'Za nastavak potvrdi svoju trenutačnu lozinku.',
            'validation_attribute' => 'trenutačna lozinka',
        ],
    ],
    'notifications' => [
        'email_change_verification_sent' => [
            'title' => 'Zahtjev za promjenu e-pošte je poslan',
            'body' => 'Zahtjev je poslan na :email. Provjeri poštu i potvrdi promjenu.',
        ],
        'throttled' => [
            'title' => 'Previše zahtjeva. Pokušaj ponovno za :seconds sekundi.',
            'body' => 'Pokušaj ponovno za :seconds sekundi.',
        ],
    ],
];
