<?php

// Die Mails der Teamkonten (Preflow\Rbac\Staff\StaffMail): {name}, {site}, {link}.
return [
    'invite' => [
        'subject' => 'Dein Zugang zu {site}',
        'action' => 'Einladung annehmen',
        'body' => "Hallo {name},\n\ndu wurdest eingeladen, {site} zu bearbeiten. Wähle hier dein Passwort: {link}\n\nDer Link ist 7 Tage gültig.\n\nFreundliche Grüße\n{site}",
        'subject_without_site' => 'Dein Zugang zur Website',
        'body_without_site' => "Hallo {name},\n\ndu wurdest eingeladen, die Website zu bearbeiten. Wähle hier dein Passwort: {link}\n\nDer Link ist 7 Tage gültig.\n\nFreundliche Grüße",
    ],
    'reset' => [
        'subject' => 'Passwort für {site} zurücksetzen',
        'action' => 'Passwort festlegen',
        'body' => "Hallo {name},\n\nhier setzt du ein neues Passwort für {site}: {link}\n\nDer Link ist eine Stunde gültig. Wenn du das nicht angefordert hast, ignoriere diese Mail.\n\nFreundliche Grüße\n{site}",
        'subject_without_site' => 'Passwort für die Website zurücksetzen',
        'body_without_site' => "Hallo {name},\n\nhier setzt du ein neues Passwort für die Website: {link}\n\nDer Link ist eine Stunde gültig. Wenn du das nicht angefordert hast, ignoriere diese Mail.\n\nFreundliche Grüße",
    ],
];
