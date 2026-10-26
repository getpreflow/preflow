<?php

// Textes d’administration de preflow/codes (Folio). Un site modifie des clés isolées dans son propre lang/fr/codes.php.
// types/fields/options : les libellés de models/code.json (Preflow\Data\ModelLabelKeys).
return [
    'types' => [
        'code' => ['label' => 'Codes', 'singular' => 'Code'],
    ],
    'fields' => [
        'code' => [
            'purpose' => ['label' => 'Usage'],
            'code' => ['label' => 'Code'],
            'label' => ['label' => 'Libellé'],
            'expires_at' => ['label' => 'Expire le', 'help' => 'Vide : n’expire jamais.'],
            'max_uses' => ['label' => 'Utilisations max.', 'help' => 'Vide : illimité.'],
            'uses' => ['label' => 'Utilisations'],
            'revoked_at' => ['label' => 'Révoqué le'],
            'form' => ['label' => 'Forme'],
        ],
    ],
    'options' => [
        'code' => [
            'form' => ['readable' => 'Lisible', 'random' => 'Aléatoire'],
        ],
    ],
];
