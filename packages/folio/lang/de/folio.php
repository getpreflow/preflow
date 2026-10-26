<?php

return [
    'common' => [
        'save' => 'Speichern',
    ],
    'layout' => [
        'language' => 'Sprache',
        'theme' => 'Design',
        'theme_system' => 'Design des Systems',
        'theme_light' => 'Helles Design',
        'theme_dark' => 'Dunkles Design',
        'your_account' => 'Dein Konto',
        'log_out' => 'Abmelden',
    ],
    'auth' => [
        'sign_in' => 'Anmelden',
    ],
    'account' => [
        'language_help' => 'Die Sprache von Folio für dich. Alle anderen behalten ihre eigene.',
    ],
    'locale' => [
        'not_offered' => 'Diese Sprache wird nicht angeboten.',
    ],
    'mail' => [
        'invite' => [
            'subject' => 'Dein Zugang zu {site}',
            'body' => "Hallo {name},\n\nfür dich wurde ein Zugang eingerichtet, um {site} in Folio zu bearbeiten. Wähle hier dein Passwort: {link}\n\nDer Link ist 7 Tage gültig.\n\nFreundliche Grüße\n{site}",
        ],
        'reset' => [
            'subject' => 'Folio-Passwort für {site} zurücksetzen',
            'body' => "Hallo {name},\n\nhier setzt du ein neues Passwort für {site}: {link}\n\nDer Link ist eine Stunde gültig. Wenn du das nicht angefordert hast, ignoriere diese Mail.\n\nFreundliche Grüße\n{site}",
        ],
    ],
];
