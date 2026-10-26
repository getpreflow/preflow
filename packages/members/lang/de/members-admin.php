<?php

// Admin-Texte von preflow/members (Folio, in der Du-Form wie Folio; die öffentlichen Texte stehen in members.php).
// Eine Site ändert einzelne Schlüssel in ihrer lang/de/members-admin.php.
// types/sections/fields: die Bezeichnungen aus models/member.json (Preflow\Data\ModelLabelKeys).
return [
    'types' => [
        'member' => ['label' => 'Mitglieder', 'singular' => 'Mitglied'],
    ],
    'sections' => [
        'members' => 'Mitglieder',
    ],
    'fields' => [
        'member' => [
            'email' => ['label' => 'E-Mail'],
            'name' => ['label' => 'Name'],
            'language' => ['label' => 'Sprache'],
            'registered_at' => ['label' => 'Registriert am'],
            'email_confirmed_at' => ['label' => 'E-Mail bestätigt am'],
            'decided_at' => ['label' => 'Entschieden am'],
            'roles' => ['label' => 'Rollen'],
        ],
    ],
];
