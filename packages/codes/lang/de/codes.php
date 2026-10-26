<?php

// Admin-Texte von preflow/codes (Folio). Eine Site ändert einzelne Schlüssel in ihrer lang/de/codes.php.
// types/fields/options: die Bezeichnungen aus models/code.json (Preflow\Data\ModelLabelKeys).
return [
    'types' => [
        'code' => ['label' => 'Codes', 'singular' => 'Code', 'empty' => 'Noch keine Codes.'],
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
    'redemptions' => [
        'title' => 'Einlösungen',
        'none' => 'Noch keine Einlösungen.',
        'by' => 'Eingelöst von',
        'time' => 'Zeitpunkt',
        'revoked' => 'widerrufen',
        'expired' => 'abgelaufen',
    ],
    'actions' => [
        'revoke' => 'Widerrufen',
        'revoke_confirm' => 'Diesen Code widerrufen? Er funktioniert sofort nicht mehr.',
    ],
    'errors' => [
        'purpose' => 'Wähle einen Zweck.',
        'not_readable' => 'Dieser Zweck erlaubt keine lesbaren Codes; zufällige Codes werden per Code ausgegeben.',
        'format' => 'Ein lesbarer Code braucht 4 bis 64 Buchstaben A–Z und Ziffern (Leerzeichen und Bindestriche werden ignoriert).',
        'taken' => 'Einen solchen Code gibt es für diesen Zweck bereits.',
        'max_uses' => '„Höchstens einlösbar“ muss eine ganze Zahl sein (leer: unbegrenzt).',
    ],
    'permissions' => [
        'revoke' => 'Codes widerrufen',
    ],
    'extension' => ['label' => 'Codes'],
];
