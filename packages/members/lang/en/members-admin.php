<?php

// Admin texts of preflow/members (Folio; scope admin, unlike the public "members" group).
// A site changes single keys in its own lang/en/members-admin.php.
// types/sections/fields: the labels of models/member.json (Preflow\Data\ModelLabelKeys).
return [
    'types' => [
        'member' => ['label' => 'Members', 'singular' => 'Member'],
    ],
    'sections' => [
        'members' => 'Members',
    ],
    'fields' => [
        'member' => [
            'email' => ['label' => 'Email'],
            'name' => ['label' => 'Name'],
            'language' => ['label' => 'Language'],
            'registered_at' => ['label' => 'Registered At'],
            'email_confirmed_at' => ['label' => 'Email Confirmed At'],
            'decided_at' => ['label' => 'Decided At'],
            'roles' => ['label' => 'Roles'],
        ],
    ],
];
