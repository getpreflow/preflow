<?php

// Textes d’administration de rbac (l’extension intégrée « rbac » de Folio : comptes de l’équipe, les
// rôles, l’éditeur des autorisations, les libellés de ses modèles). Les textes de preflow/rbac
// lui-même (refus, rôles créés) sont son groupe « rbac ». Un site change des clés dans son propre
// lang/fr/rbac-admin.php. :email est l’adresse d’un compte de l’équipe.
return [
    'delegation' => [
        'own' => 'Vous perdriez le droit de modifier les rôles.',
        'last_role' => 'Il ne resterait aucun rôle de l’équipe autorisé à modifier les rôles.',
        'duplicate' => 'Un rôle nommé :name existe déjà pour « :population ».',
    ],
    'staff' => [
        'invited' => 'Invitation envoyée à :email.',
        'invite_failed' => 'Le compte est enregistré, mais aucune invitation n’a été envoyée. Vérifiez la configuration des e-mails (config/notify.php), puis utilisez « Envoyer l’invitation ».',
        'mail_failed' => 'L’e-mail n’a pas pu être envoyé. Vérifiez la configuration des e-mails (config/notify.php).',
        'reset_sent' => 'Lien de réinitialisation envoyé à :email.',
        'disabled' => 'Compte désactivé. Ses sessions et ses liens ont pris fin.',
        'enabled' => 'Compte activé.',
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
    // Les nouvelles autorisations apportées par une mise à jour : l’avis au-dessus de la liste des
    // rôles, ses actions, la ligne du tableau de bord (:count, « un|plusieurs »). :role est le libellé d’un rôle.
    'new' => [
        'title' => 'Nouvelles autorisations',
        'intro' => 'Ces autorisations sont arrivées avec une mise à jour. Elles ne sont données automatiquement à aucun rôle.',
        'covered' => 'Déjà dans :',
        'pick' => 'Ajouter au rôle…',
        'add' => 'Ajouter',
        'dismiss' => 'Ignorer',
        'dashboard' => ':count nouvelle autorisation à examiner dans Rôles|:count nouvelles autorisations à examiner dans Rôles',
        'needs_you' => '{0} :count nouvelle autorisation dans Rôles|{1} :count nouvelle autorisation dans Rôles|[2,*] :count nouvelles autorisations dans Rôles',
        'needs_you_text' => 'Une mise à jour les a apportées. Décidez rôle par rôle.',
        'added' => 'Ajouté au rôle :role.',
        'dismissed' => 'Ignoré.',
        'no_role' => 'Choisissez le rôle auquel l’ajouter.',
        'unavailable' => 'Les nouvelles autorisations ne peuvent pas encore être vérifiées : lancez preflow migrate.',
        'failed' => "Le rôle n'a pas pu être enregistré. L'erreur figure dans le journal du serveur.",
    ],
    'extension' => ['label' => 'Rôles'],
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
