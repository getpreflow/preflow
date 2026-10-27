<?php

// The invitation mail (CodeMailer). {placeholders} are filled by the mailer; a site changes single keys
// in its own lang/de/codes-mail.php.
return [
    'invite' => [
        'subject' => 'Sie sind eingeladen',
        'action' => 'Einladung öffnen',
        'intro' => '{from_name} lädt Sie ein.',
        'intro_anonymous' => 'Sie sind eingeladen.',
        'open' => 'Zur Einladung: {link}',
        'validity' => 'Der Link ist persönlich und gültig bis {expires}.',
        'personal' => 'Der Link ist persönlich.',
    ],
];
