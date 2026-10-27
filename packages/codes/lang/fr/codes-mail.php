<?php

// The invitation mail (CodeMailer). {placeholders} are filled by the mailer; a site changes single keys
// in its own lang/fr/codes-mail.php.
return [
    'invite' => [
        'subject' => 'Vous êtes invité·e',
        'action' => "Ouvrir l'invitation",
        'intro' => '{from_name} vous invite.',
        'intro_anonymous' => 'Vous êtes invité·e.',
        'open' => 'Votre invitation : {link}',
        'validity' => "Le lien est personnel et valable jusqu'au {expires}.",
        'personal' => 'Le lien est personnel.',
    ],
];
