<?php

return [
    'mail' => [
        'invite' => [
            'subject' => 'Votre compte pour {site}',
            'body' => "Bonjour {name},\n\nun compte a été créé pour vous permettre de modifier {site} dans Folio. Choisissez votre mot de passe ici : {link}\n\nLe lien est valable 7 jours.",
        ],
        'reset' => [
            'subject' => 'Réinitialiser votre mot de passe Folio pour {site}',
            'body' => "Bonjour {name},\n\ndéfinissez un nouveau mot de passe pour {site} ici : {link}\n\nLe lien est valable une heure. Si vous n'avez rien demandé, ignorez ce message.",
        ],
    ],
];
