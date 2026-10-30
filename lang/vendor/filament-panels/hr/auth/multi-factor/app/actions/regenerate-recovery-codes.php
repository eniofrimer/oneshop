<?php

return [
    'label' => 'Generiraj nove rezervne kodove',
    'modal' => [
        'heading' => 'Generiraj nove rezervne kodove',
        'description' => 'Novi kodovi odmah zamjenjuju postojeće rezervne kodove.',
        'form' => [
            'code' => [
                'label' => 'Šesteroznamenkasti kod iz aplikacije', 'validation_attribute' => 'kod',
                'messages' => [
                    'invalid' => 'Uneseni kod nije valjan.',
                    'rate_limited' => 'Previše pokušaja. Pokušaj ponovno kasnije.',
                ],
            ],
            'password' => ['label' => 'Trenutačna lozinka', 'validation_attribute' => 'lozinka'],
        ],
        'actions' => ['submit' => ['label' => 'Generiraj nove rezervne kodove']],
    ],
    'notifications' => ['regenerated' => ['title' => 'Novi rezervni kodovi su generirani']],
    'show_new_recovery_codes' => ['modal' => [
        'heading' => 'Novi rezervni kodovi',
        'description' => 'Spremi rezervne kodove na sigurno. Prikazat će se samo jednom i trebaju ti ako izgubiš pristup aplikaciji.',
        'actions' => ['submit' => ['label' => 'Zatvori']],
    ]],
];
