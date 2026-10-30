<?php

return [
    'label' => 'Isključi',
    'modal' => [
        'heading' => 'Isključi aplikaciju za autentifikaciju',
        'description' => 'Potvrdi isključivanje ove aplikacije. Ako tvoj račun zahtijeva dvofaktorsku provjeru, prije nastavka moraš je ponovno postaviti.',
        'form' => [
            'password' => ['label' => 'Trenutačna lozinka', 'validation_attribute' => 'trenutačna lozinka'],
            'code' => [
                'label' => 'Šesteroznamenkasti kod iz aplikacije',
                'validation_attribute' => 'kod',
                'actions' => ['use_recovery_code' => ['label' => 'Upotrijebi rezervni kod']],
                'messages' => [
                    'invalid' => 'Uneseni kod nije valjan.',
                    'rate_limited' => 'Previše pokušaja. Pokušaj ponovno kasnije.',
                ],
            ],
            'recovery_code' => [
                'label' => 'Rezervni kod', 'validation_attribute' => 'rezervni kod',
                'messages' => [
                    'invalid' => 'Uneseni rezervni kod nije valjan.',
                    'rate_limited' => 'Previše pokušaja. Pokušaj ponovno kasnije.',
                ],
            ],
        ],
        'actions' => ['submit' => ['label' => 'Isključi aplikaciju za autentifikaciju']],
    ],
    'notifications' => ['disabled' => ['title' => 'Aplikacija za autentifikaciju je isključena']],
];
