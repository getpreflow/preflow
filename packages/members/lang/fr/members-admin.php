<?php

// Textes d’administration de preflow/members (Folio ; les textes publics sont dans members.php).
// Un site modifie des clés isolées dans son propre lang/fr/members-admin.php.
// types/sections/fields : les libellés de models/member.json (Preflow\Data\ModelLabelKeys).
return [
    'types' => [
        'member' => ['label' => 'Membres', 'singular' => 'Membre'],
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
    ],
];
