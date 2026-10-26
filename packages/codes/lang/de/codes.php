<?php

// Admin-Texte von preflow/codes (Folio). Eine Site ändert einzelne Schlüssel in ihrer lang/de/codes.php.
// types/fields/options: die Bezeichnungen aus models/code.json (Preflow\Data\ModelLabelKeys).
return [
    'types' => [
        'code' => ['label' => 'Codes', 'singular' => 'Code'],
    ],
    'fields' => [
        'code' => [
            'purpose' => ['label' => 'Zweck'],
            'code' => ['label' => 'Code'],
            'label' => ['label' => 'Bezeichnung'],
            'expires_at' => ['label' => 'Läuft ab am', 'help' => 'Leer: läuft nie ab.'],
            'max_uses' => ['label' => 'Höchstens einlösbar', 'help' => 'Leer: unbegrenzt.'],
            'uses' => ['label' => 'Eingelöst'],
            'revoked_at' => ['label' => 'Widerrufen am'],
            'form' => ['label' => 'Form'],
        ],
    ],
    'options' => [
        'code' => [
            'form' => ['readable' => 'Lesbar', 'random' => 'Zufällig'],
        ],
    ],
];
