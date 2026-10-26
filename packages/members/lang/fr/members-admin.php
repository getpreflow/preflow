<?php

// Textes d’administration de preflow/members (Folio ; les textes publics sont dans members.php).
// Un site modifie des clés isolées dans son propre lang/fr/members-admin.php.
// types/sections/fields : les libellés de models/member.json et du formulaire des paramètres (Preflow\Data\ModelLabelKeys).
return [
    'types' => [
        'member' => ['label' => 'Membres', 'singular' => 'Membre', 'empty' => 'Aucun membre pour l’instant.'],
        'members_settings' => ['label' => 'Paramètres des membres', 'singular' => 'Paramètres des membres'],
    ],
    'sections' => [
        'members' => 'Membres',
    ],
    'fields' => [
        'member' => [
            'email' => ['label' => 'Adresse e-mail'],
            'name' => ['label' => 'Nom'],
            'language' => ['label' => 'Langue'],
            'registered_at' => ['label' => 'Inscrit le'],
            'email_confirmed_at' => ['label' => 'E-mail confirmé le'],
            'decided_at' => ['label' => 'Décision le'],
            'roles' => ['label' => 'Rôles'],
        ],
        'members_settings' => [
            'registration_open' => ['label' => 'Inscriptions ouvertes'],
            'notice_email' => ['label' => 'Prévenir des membres en attente', 'help' => 'Adresse qui reçoit un e-mail quand quelqu’un attend une validation'],
            'accept_message' => ['label' => 'Message d’acceptation', 'help' => 'Prérempli dans le panneau Décision ; vide signifie le texte standard affiché dans le champ. {name} et {login_url} sont remplacés.'],
            'decline_message' => ['label' => 'Message de refus', 'help' => 'Prérempli dans le panneau Décision ; vide signifie le texte standard affiché dans le champ. {name} est remplacé.'],
        ],
    ],
    'list' => ['state' => 'État'],
    'states' => [
        'unconfirmed' => 'Non confirmé',
        'waiting' => 'En attente',
        'member' => 'Membre',
        'declined' => 'Refusé',
    ],
    'decision' => [
        'title' => 'Décision',
        'registered' => 'Inscrit le :date',
        'unconfirmed' => 'Pas encore confirmé — rien à décider.',
        'accept' => 'Accepter',
        'decline' => 'Refuser',
        'accept_send' => 'Accepter et envoyer',
        'decline_send' => 'Refuser et envoyer',
        'message_to' => 'Message à :name',
        'sent_in' => 'Envoyé en :language. Le texte par défaut vient des paramètres des membres ; modifiez-le librement.',
        'empty_message' => 'Le message est vide.',
        'cant_accept' => ':state : impossible d’accepter — rien n’a été modifié.',
        'cant_decline' => ':state : impossible de refuser — rien n’a été modifié.',
        'mail_failed' => 'L’e-mail n’a pas pu être envoyé — rien n’a été modifié.',
        'languages' => [
            'en' => 'anglais',
            'de' => 'allemand',
            'fr' => 'français',
            'it' => 'italien',
            'es' => 'espagnol',
            'nl' => 'néerlandais',
            'pt' => 'portugais',
            'rm' => 'romanche',
        ],
    ],
    'errors' => [
        'email_taken' => 'Un autre membre utilise déjà cette adresse e-mail.',
    ],
    'permissions' => [
        'access' => 'Utiliser l’espace membres',
        'decide' => 'Accepter ou refuser des membres',
    ],
    'extension' => ['label' => 'Membres'],
    'roles' => [
        'members' => [
            'pending' => 'En attente de validation',
            'member' => 'Membre',
            'rejected' => 'Refusé',
        ],
    ],
];
