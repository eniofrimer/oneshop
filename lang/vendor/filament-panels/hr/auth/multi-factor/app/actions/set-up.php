<?php

return [
    'label' => 'Postavi',
    'modal' => [
        'heading' => 'Postavi aplikaciju za autentifikaciju',
        'description' => 'Za dovršetak pripremi aplikaciju za autentifikaciju na svom uređaju.',
        'content' => [
            'qr_code' => [
                'instruction' => 'Skeniraj QR kod aplikacijom za autentifikaciju:',
                'alt' => 'QR kod za aplikaciju za autentifikaciju',
            ],
            'text_code' => [
                'instruction' => 'Ili ručno unesi ovaj ključ:',
                'messages' => ['copied' => 'Kopirano'],
            ],
            'recovery_codes' => [
                'instruction' => 'Spremi rezervne kodove na sigurno. Prikazat će se samo jednom i trebaju ti ako izgubiš pristup aplikaciji.',
            ],
        ],
        'form' => [
            'password' => ['label' => 'Trenutačna lozinka', 'validation_attribute' => 'trenutačna lozinka'],
            'code' => [
                'label' => 'Šesteroznamenkasti kod iz aplikacije',
                'validation_attribute' => 'kod',
                'below_content' => 'Kod iz aplikacije potreban je pri prijavi i potvrdi osjetljivih radnji.',
                'messages' => [
                    'invalid' => 'Uneseni kod nije valjan.',
                    'rate_limited' => 'Previše pokušaja. Pokušaj ponovno kasnije.',
                ],
            ],
        ],
        'actions' => ['submit' => ['label' => 'Omogući aplikaciju za autentifikaciju']],
    ],
    'notifications' => ['enabled' => ['title' => 'Aplikacija za autentifikaciju je omogućena']],
];
