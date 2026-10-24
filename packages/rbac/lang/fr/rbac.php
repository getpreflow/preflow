<?php

// Messages of preflow/rbac (delegation; staff account actions). A site changes single keys in its own lang/fr/rbac.php.
// :patterns is the comma-separated list of the permissions the editor doesn't hold;
// :names the role names that no staff role has; :email a staff account's address.
return [
    'delegation' => [
        'grant' => "Vous ne pouvez pas accorder des autorisations que vous n'avez pas : :patterns",
        'account' => "Vous ne pouvez pas modifier un compte dont vous ne pouvez pas accorder les rôles.",
        'last' => "Au moins un compte doit conserver le droit de gérer les comptes de l'équipe et les rôles.",
        'unknown' => 'Rôle inconnu : :names.',
    ],    'staff' => [
        'invited' => 'Invitation envoyée à :email.',
        'invite_failed' => "Le compte est enregistré, mais aucune invitation n'a été envoyée. Vérifiez la configuration des e-mails (config/notify.php), puis utilisez « Envoyer l'invitation ».",
        'mail_failed' => "L'e-mail n'a pas pu être envoyé. Vérifiez la configuration des e-mails (config/notify.php).",
        'no_sign_in' => "Les comptes de l'équipe ne peuvent pas encore se connecter sur ce site : le guard par défaut de config/auth.php n'a pas de fournisseur pour ces comptes.",
        'has_password' => 'Ce compte a déjà un mot de passe. Envoyez plutôt un lien de réinitialisation.',
        'inactive' => "Ce compte est désactivé. Activez-le d'abord.",
        'reset_sent' => 'Lien de réinitialisation envoyé à :email.',
        'disabled' => 'Compte désactivé. Ses sessions et ses liens ont pris fin.',
        'enabled' => 'Compte activé.',
    ],
];
