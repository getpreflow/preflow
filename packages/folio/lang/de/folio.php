<?php

return [
    'mail' => [
        'invite' => [
            'subject' => 'Dein Zugang zu {site}',
            'body' => "Hallo {name}\n\nfür dich wurde ein Zugang eingerichtet, um {site} in Folio zu bearbeiten. Wähle hier dein Passwort: {link}\n\nDer Link ist 7 Tage gültig.",
        ],
        'reset' => [
            'subject' => 'Folio-Passwort für {site} zurücksetzen',
            'body' => "Hallo {name}\n\nhier setzt du ein neues Passwort für {site}: {link}\n\nDer Link ist eine Stunde gültig. Wenn du das nicht angefordert hast, ignoriere diese Mail.",
        ],
    ],
];
