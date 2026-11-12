<?php

// Admin texts of preflow/codes (Folio). A site changes single keys in its own lang/en/codes.php.
// types/fields/options: the labels of models/code.json (Preflow\Data\ModelLabelKeys).
return [
    'types' => [
        'code' => ['label' => 'Codes', 'singular' => 'Code', 'empty' => 'No codes yet.'],
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
            'recipient' => ['label' => 'Recipient'],
            'created_at' => ['label' => 'Created at'],
        ],
    ],
    'options' => [
        'code' => [
            'form' => ['readable' => 'Readable', 'random' => 'Random'],
        ],
    ],
    // The Redemptions column and panel; :purpose is never shown, the column's states are lower case.
    'redemptions' => [
        'title' => 'Redemptions',
        'none' => 'No redemptions yet.',
        'by' => 'Redeemed by',
        'time' => 'Time',
        'revoked' => 'revoked',
        'expired' => 'expired',
    ],
    'actions' => [
        'revoke' => 'Revoke',
        'revoke_confirm' => 'Revoke this code? It stops working at once.',
    ],
    // Refusals when a code is saved in Folio.
    'errors' => [
        'purpose' => 'Choose a purpose.',
        'not_readable' => 'This purpose does not allow readable codes; random codes are issued by code.',
        'format' => 'A readable code needs 4 to 64 letters A-Z and digits (spaces and dashes are ignored).',
        'taken' => 'A code like this already exists for this purpose.',
        'max_uses' => 'Max uses must be a whole number (empty: unlimited).',
        'changed' => 'The code changed meanwhile; reload it and try again.',
        'unknown_code' => 'This code no longer exists.',
        'revoked' => 'A revoked code cannot be changed.',
        'used_up' => 'A used-up code cannot be changed.',
        'expiry' => 'Enter a valid date and time.',
        'expiry_past' => 'A code can only expire at a time in the future.',
        'max_uses_low' => 'Max uses cannot be below the uses so far.',
        'bound_single_use' => 'A code bound to a recipient has exactly one use.',
    ],
    // The roles screen: the extension's permission and its heading.
    'permissions' => [
        'revoke' => 'Revoke codes',
    ],
    'extension' => ['label' => 'Codes'],
];
