<?php

// Folio's mails to its accounts (invite, reset), in the site's default language. A site changes
// single phrases in its own lang/{locale}/folio.php. {name}, {site} and {link} are filled in.
return [
    'mail' => [
        'invite' => [
            'subject' => 'Your account for {site}',
            'body' => "Hi {name},\n\nan account was set up for you to edit {site} in Folio. Choose your password here: {link}\n\nThe link works for 7 days.",
        ],
        'reset' => [
            'subject' => 'Reset your Folio password for {site}',
            'body' => "Hi {name},\n\nset a new password for {site} here: {link}\n\nThe link works for one hour. If you didn't ask for this, ignore this mail.",
        ],
    ],
];
