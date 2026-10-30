<?php

return [
    'management_schema' => ['actions' => [
        'label' => 'Aplikacija za autentifikaciju',
        'below_content' => 'Aplikacija generira privremene kodove za provjeru prijave.',
        'messages' => ['enabled' => 'Omogućeno', 'disabled' => 'Isključeno'],
    ]],
    'login_form' => [
        'label' => 'Upotrijebi kod iz aplikacije za autentifikaciju',
        'code' => [
            'label' => 'Šesteroznamenkasti kod iz aplikacije',
            'validation_attribute' => 'kod',
            'actions' => ['use_recovery_code' => ['label' => 'Upotrijebi rezervni kod']],
            'messages' => ['invalid' => 'Uneseni kod nije valjan.'],
        ],
        'recovery_code' => [
            'label' => 'Rezervni kod',
            'validation_attribute' => 'rezervni kod',
            'messages' => ['invalid' => 'Uneseni rezervni kod nije valjan.'],
        ],
    ],
];
