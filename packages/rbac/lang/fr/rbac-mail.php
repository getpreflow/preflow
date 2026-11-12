<?php

// Les e-mails des comptes de l’équipe (Preflow\Rbac\Staff\StaffMail) : {name}, {site}, {link}.
return [
    'invite' => [
        'subject' => 'Votre compte pour {site}',
        'action' => 'Accepter l’invitation',
        'body' => "Bonjour {name},\n\nvous avez été invité(e) à modifier {site}. Choisissez votre mot de passe ici : {link}\n\nLe lien est valable 7 jours.\n\nCordialement\n{site}",
        'subject_without_site' => 'Votre compte pour le site',
        'body_without_site' => "Bonjour {name},\n\nvous avez été invité(e) à modifier le site. Choisissez votre mot de passe ici : {link}\n\nLe lien est valable 7 jours.\n\nCordialement",
    ],
    'reset' => [
        'subject' => 'Réinitialiser votre mot de passe pour {site}',
        'action' => 'Définir mon mot de passe',
        'body' => "Bonjour {name},\n\ndéfinissez un nouveau mot de passe pour {site} ici : {link}\n\nLe lien est valable une heure. Si vous n’avez rien demandé, ignorez ce message.\n\nCordialement\n{site}",
        'subject_without_site' => 'Réinitialiser votre mot de passe pour le site',
        'body_without_site' => "Bonjour {name},\n\ndéfinissez un nouveau mot de passe pour le site ici : {link}\n\nLe lien est valable une heure. Si vous n’avez rien demandé, ignorez ce message.\n\nCordialement",
    ],
];
