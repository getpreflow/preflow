<?php

// Textes du cœur de preflow/rbac : refus pour les comptes de l’équipe et les rôles (StaffAccounts,
// les commandes, les formulaires d’une administration), les rôles qu’il crée. Les textes propres d’une
// administration sont un groupe à part (Folio : rbac-admin). Un site change des clés dans son propre lang/fr/rbac.php.
// :patterns est la liste, séparée par des virgules, des autorisations que l’éditeur n’a pas ;
// :names les noms de rôles qu’aucun rôle de l’équipe n’a.
return [
    'delegation' => [
        'grant' => 'Vous ne pouvez pas accorder des autorisations que vous n’avez pas : :patterns',
        'account' => 'Vous ne pouvez pas modifier un compte dont vous ne pouvez pas accorder les rôles.',
        'last' => 'Au moins un compte doit conserver le droit de gérer les comptes de l’équipe et les rôles.',
        'unknown' => 'Rôle inconnu : :names.',
    ],
    'staff' => [
        'no_sign_in' => 'Les comptes de l’équipe ne peuvent pas encore se connecter sur ce site : le guard staff n’a pas de fournisseur pour ces comptes.',
        'no_app_url' => 'Définissez app.url pour envoyer des liens de connexion.',
        'config_email' => 'Cette adresse e-mail appartient à un administrateur configuré.',
        'has_password' => 'Ce compte a déjà un mot de passe. Envoyez plutôt un lien de réinitialisation.',
        'inactive' => 'Ce compte est désactivé. Activez-le d’abord.',
        'email_taken' => 'Un autre compte utilise déjà cette adresse e-mail.',
        'email_invalid' => 'Ce n’est pas une adresse e-mail.',
        'password_short' => 'Le mot de passe doit compter au moins :min caractères.',
        'no_link_page' => 'Aucune page pour définir un mot de passe n’est configurée ; utilisez staff:password, ou installez une administration qui en fournit une.',
        'no_type' => 'Ce site n’a pas de comptes d’équipe : config/auth.php ne nomme aucun fournisseur d’utilisateurs de la classe Preflow\\Auth\\Record\\RecordUserProvider avec \'type\' => \'staff\'.',
        'gone' => 'Ce compte n’existe plus.',
    ],
    'roles' => [
        'staff' => ['admin' => 'Administrateur'],
    ],
];
