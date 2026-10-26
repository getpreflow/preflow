<?php

// Textes d’administration de preflow/codes (Folio). Un site modifie des clés isolées dans son propre lang/fr/codes.php.
// types/fields/options : les libellés de models/code.json (Preflow\Data\ModelLabelKeys).
return [
    'types' => [
        'code' => ['label' => 'Codes', 'singular' => 'Code', 'empty' => 'Aucun code pour l’instant.'],
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
    'redemptions' => [
        'title' => 'Échanges',
        'none' => 'Aucun échange pour l’instant.',
        'by' => 'Échangé par',
        'time' => 'Date',
        'revoked' => 'révoqué',
        'expired' => 'expiré',
    ],
    'actions' => [
        'revoke' => 'Révoquer',
        'revoke_confirm' => 'Révoquer ce code ? Il cesse de fonctionner immédiatement.',
    ],
    'errors' => [
        'purpose' => 'Choisissez un usage.',
        'not_readable' => 'Cet usage n’autorise pas les codes lisibles ; les codes aléatoires sont émis par le programme du site.',
        'format' => 'Un code lisible compte de 4 à 64 lettres A à Z et chiffres (les espaces et tirets sont ignorés).',
        'taken' => 'Un code identique existe déjà pour cet usage.',
        'max_uses' => 'Les utilisations max. doivent être un nombre entier (vide : illimité).',
    ],
    'permissions' => [
        'revoke' => 'Révoquer des codes',
    ],
    'extension' => ['label' => 'Codes'],
];
