<?php

return [
    'common' => [
        'save' => 'Enregistrer',
    ],
    'layout' => [
        'language' => 'Langue',
        'theme' => 'Thème',
        'theme_system' => 'Thème du système',
        'theme_light' => 'Thème clair',
        'theme_dark' => 'Thème sombre',
        'your_account' => 'Votre compte',
        'log_out' => 'Se déconnecter',
    ],
    'auth' => [
        'sign_in' => 'Se connecter',
    ],
    'account' => [
        'language_help' => 'La langue de Folio pour votre compte. Les autres utilisateurs gardent la leur.',
    ],
    'locale' => [
        'not_offered' => "Cette langue n'est pas proposée.",
    ],
    'mail' => [
        'invite' => [
            'subject' => 'Votre compte pour {site}',
            'body' => "Bonjour {name},\n\nun compte a été créé pour vous permettre de modifier {site} dans Folio. Choisissez votre mot de passe ici : {link}\n\nLe lien est valable 7 jours.\n\nCordialement\n{site}",
        ],
        'reset' => [
            'subject' => 'Réinitialiser votre mot de passe Folio pour {site}',
            'body' => "Bonjour {name},\n\ndéfinissez un nouveau mot de passe pour {site} ici : {link}\n\nLe lien est valable une heure. Si vous n'avez rien demandé, ignorez ce message.\n\nCordialement\n{site}",
        ],
    ],
];
