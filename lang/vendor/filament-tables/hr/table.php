<?php

return [
    'column_manager' => [
        'position' => 'Položaj :position od :count.',
        'position_in_group' => 'Položaj :position od :count u grupi :group.',
        'reordered' => ':column premješten na položaj :position od :count.',
        'reordered_deferred' => ':column premješten na položaj :position od :count. Primijenite stupce za spremanje izmjena.',
        'reordered_in_group' => ':column premješten na položaj :position od :count u grupi :group.',
        'reordered_in_group_deferred' => ':column premješten na položaj :position od :count u grupi :group. Primijenite stupce za spremanje izmjena.',
        'actions' => [
            'apply' => ['label' => 'Primijeni stupce'],
            'move_up' => ['label' => 'Pomakni :column gore'],
            'move_down' => ['label' => 'Pomakni :column dolje'],
            'reorder' => ['label' => 'Promijeni redoslijed stupca'],
            'reset' => ['label' => 'Vrati početne postavke'],
        ],
    ],
    'columns' => [
        'editable' => [
            'saving' => 'Spremanje izmjene…', 'saved' => 'Izmjena je spremljena.',
            'failure' => 'Spremanje nije potvrđeno. Osvježite stranicu prije ponovnog pokušaja.',
            'unavailable' => 'Ovu stavku više nije moguće uređivati.',
        ],
        'icon' => ['boolean' => ['true' => 'Da', 'false' => 'Ne']],
        'select' => [
            'loading_message' => 'Učitavanje…', 'no_options_message' => 'Nema dostupnih opcija.',
            'no_search_results_message' => 'Nema rezultata pretrage.', 'placeholder' => 'Odaberite opciju',
            'searching_message' => 'Pretraživanje…', 'search_prompt' => 'Upišite pojam za pretragu',
        ],
    ],
    'actions' => [
        'reorder_record' => ['label' => 'Promijeni redoslijed stavke :key'],
        'move_record_up' => ['label' => 'Pomakni :label gore'],
        'move_record_down' => ['label' => 'Pomakni :label dolje'],
        'toggle_record_content' => ['label' => 'Proširi ili sažmi stavku :key'],
    ],
    'filters' => ['select' => ['relationship' => ['empty_option_label' => 'Nijedna']]],
    'loading' => 'Učitavanje…',
    'reordering' => [
        'position' => ':label, položaj :position od :count.',
        'moved' => ':label premješten na položaj :position od :count.',
    ],
    'result_count' => '{0} Nema rezultata|{1} :count rezultat|[2,4] :count rezultata|[5,*] :count rezultata',
    'default_model_label' => 'zapis',
];
