<?php

// Textes d’administration de preflow/rbac (Folio : délégation, comptes de l’équipe, les rôles). Un site
// change des clés dans son propre lang/fr/rbac.php.
// :patterns est la liste, séparée par des virgules, des autorisations que l’éditeur n’a pas ;
// :names les noms de rôles qu’aucun rôle de l’équipe n’a ; :email l’adresse d’un compte de l’équipe.
return [
    'delegation' => [
        'grant' => 'Vous ne pouvez pas accorder des autorisations que vous n’avez pas : :patterns',
        'account' => 'Vous ne pouvez pas modifier un compte dont vous ne pouvez pas accorder les rôles.',
        'last' => 'Au moins un compte doit conserver le droit de gérer les comptes de l’équipe et les rôles.',
        'unknown' => 'Rôle inconnu : :names.',
        'own' => 'Vous perdriez le droit de modifier les rôles.',
        'last_role' => 'Il ne resterait aucun rôle de l’équipe autorisé à modifier les rôles.',
        'duplicate' => 'Un rôle nommé :name existe déjà pour « :population ».',
    ],
    'staff' => [
        'invited' => 'Invitation envoyée à :email.',
        'invite_failed' => 'Le compte est enregistré, mais aucune invitation n’a été envoyée. Vérifiez la configuration des e-mails (config/notify.php), puis utilisez « Envoyer l’invitation ».',
        'mail_failed' => 'L’e-mail n’a pas pu être envoyé. Vérifiez la configuration des e-mails (config/notify.php).',
        'no_sign_in' => 'Les comptes de l’équipe ne peuvent pas encore se connecter sur ce site : le guard par défaut de config/auth.php n’a pas de fournisseur pour ces comptes.',
        'no_app_url' => 'Définissez app.url pour envoyer des liens de connexion.',
        'config_email' => 'Cette adresse e-mail appartient à un administrateur configuré.',
        'has_password' => 'Ce compte a déjà un mot de passe. Envoyez plutôt un lien de réinitialisation.',
        'inactive' => 'Ce compte est désactivé. Activez-le d’abord.',
        'reset_sent' => 'Lien de réinitialisation envoyé à :email.',
        'disabled' => 'Compte désactivé. Ses sessions et ses liens ont pris fin.',
        'enabled' => 'Compte activé.',
        'email_taken' => 'Un autre compte utilise déjà cette adresse e-mail.',
    ],
    'account' => [
        'title' => 'Compte',
        'active' => 'Actif',
        'disabled' => 'Désactivé',
        'no_password' => 'Pas encore de mot de passe',
        'disabled_help' => 'Ne peut pas se connecter. Ses sessions et ses liens ont pris fin à la désactivation.',
        'invited_help' => 'Ne peut pas se connecter tant que le lien d’invitation n’a pas servi à définir un mot de passe.',
        'active_help' => 'Se connecte avec son propre mot de passe.',
        'disable_confirm' => 'Désactiver :email ? Ses sessions et ses liens prennent fin immédiatement.',
    ],
    'actions' => [
        'invite' => 'Envoyer l’invitation',
        'reset' => 'Envoyer un lien de réinitialisation',
        'disable' => 'Désactiver',
        'enable' => 'Activer',
    ],
    'matrix' => [
        'everything' => 'Tout',
        'everything_hint' => 'Accorde toutes les autorisations, actuelles et celles des futures extensions (:pattern)',
        'unknown' => 'Inconnues, retirées à l’enregistrement : :names',
        'type' => 'Type',
        'all' => 'Toutes',
        'none' => 'Pas d’autorisation « :verb » pour :type',
        'other' => 'Autres autorisations',
        'other_group' => 'Autres',
        'denied' => 'Vous ne pouvez pas accorder ceci',
    ],
    'roles_field' => [
        'not_defined' => ':role (non défini)',
        'no_population' => 'Aucun public : indiquez « users » pour le type ou « population » pour le champ.',
    ],
    'extension' => ['label' => 'Rôles'],
    'roles' => [
        'staff' => ['admin' => 'Administrateur'],
    ],
    // types/fields/options : les libellés de models/staff.json et models/rbac_role.json (Preflow\Data\ModelLabelKeys).
    'types' => [
        'staff' => ['label' => 'Équipe', 'singular' => 'Membre de l’équipe', 'empty' => 'Aucun membre de l’équipe pour l’instant.'],
        'rbac_role' => ['label' => 'Rôles', 'singular' => 'Rôle', 'empty' => 'Aucun rôle pour l’instant.'],
    ],
    'fields' => [
        'staff' => [
            'email' => ['label' => 'Adresse e-mail'],
            'name' => ['label' => 'Nom'],
            'roles' => ['label' => 'Rôles'],
            'active' => ['label' => 'Actif', 'help' => 'Un compte inactif ne peut pas se connecter.'],
            'last_login_at' => ['label' => 'Dernière connexion'],
            'created_at' => ['label' => 'Créé le'],
        ],
        'rbac_role' => [
            'name' => ['label' => 'Nom'],
            'label' => ['label' => 'Libellé'],
            'population' => ['label' => 'Public'],
            'permissions' => ['label' => 'Autorisations'],
        ],
    ],
    'options' => [
        'rbac_role' => [
            'population' => ['staff' => 'Équipe', 'members' => 'Membres'],
        ],
    ],
];
