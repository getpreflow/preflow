<?php

// Admin texts of preflow/codes (Folio). A site changes single keys in its own lang/en/codes.php.
// types/fields/options: the labels of models/code.json (Preflow\Data\ModelLabelKeys).
return [
    'types' => [
        'code' => ['label' => 'Codes', 'singular' => 'Code'],
    ],
    'fields' => [
        'code' => [
            'purpose' => ['label' => 'Purpose'],
            'code' => ['label' => 'Code'],
            'label' => ['label' => 'Label'],
            'expires_at' => ['label' => 'Expires at', 'help' => 'Empty: never expires.'],
            'max_uses' => ['label' => 'Max uses', 'help' => 'Empty: unlimited.'],
            'uses' => ['label' => 'Uses'],
            'revoked_at' => ['label' => 'Revoked at'],
            'form' => ['label' => 'Form'],
        ],
    ],
    'options' => [
        'code' => [
            'form' => ['readable' => 'Readable', 'random' => 'Random'],
        ],
    ],
];
