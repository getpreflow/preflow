<?php

// The invitation mail (CodeMailer). {placeholders} are filled by the mailer; a site changes single keys
// in its own lang/en/codes-mail.php.
return [
    'invite' => [
        'subject' => "You're invited",
        'action' => "Open invitation",
        'intro' => '{from_name} invites you.',
        'intro_anonymous' => "You're invited.",
        'open' => 'Open your invitation: {link}',
        'validity' => 'The link is personal and valid until {expires}.',
        'personal' => 'The link is personal.',
    ],
];
