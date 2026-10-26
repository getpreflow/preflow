<?php

// Messages of preflow/rbac (delegation; staff account actions). A site changes single keys in its own lang/en/rbac.php.
// :patterns is the comma-separated list of the permissions the editor doesn't hold;
// :names the role names that no staff role has; :email a staff account's address.
return [
    'delegation' => [
        'grant' => "You can't grant permissions you don't have: :patterns",
        'account' => "You can't change an account with roles you can't grant.",
        'last' => 'At least one account must keep the right to manage staff and roles.',
        'unknown' => 'Unknown role: :names.',
    ],    'staff' => [
        'invited' => 'Invitation sent to :email.',
        'invite_failed' => 'The account is saved, but no invitation was sent. Check the mail setup (config/notify.php), then use "Send invitation".',
        'mail_failed' => 'The mail could not be sent. Check the mail setup (config/notify.php).',
        'no_sign_in' => "Staff accounts can't sign in on this site yet: the default guard in config/auth.php has no staff record provider.",
        'no_app_url' => 'Set app.url to send sign-in links.',
        'config_email' => 'This e-mail address belongs to a configured admin.',
        'has_password' => 'This account already has a password. Send a reset link instead.',
        'inactive' => 'This account is disabled. Enable it first.',
        'reset_sent' => 'Reset link sent to :email.',
        'disabled' => 'Account disabled. Its sessions and links have ended.',
        'enabled' => 'Account enabled.',
    ],
    // types/fields/options: the labels of models/staff.json and models/rbac_role.json (Preflow\Data\ModelLabelKeys).
    'types' => [
        'staff' => ['label' => 'Staff', 'singular' => 'Staff'],
        'rbac_role' => ['label' => 'Roles', 'singular' => 'Role'],
    ],
    'fields' => [
        'staff' => [
            'email' => ['label' => 'Email'],
            'name' => ['label' => 'Name'],
            'roles' => ['label' => 'Roles'],
            'active' => ['label' => 'Active', 'help' => 'An inactive account can\'t sign in.'],
            'last_login_at' => ['label' => 'Last sign-in'],
            'created_at' => ['label' => 'Created'],
        ],
        'rbac_role' => [
            'name' => ['label' => 'Name'],
            'label' => ['label' => 'Label'],
            'population' => ['label' => 'Population'],
            'permissions' => ['label' => 'Permissions'],
        ],
    ],
    'options' => [
        'rbac_role' => [
            'population' => ['staff' => 'Staff', 'members' => 'Members'],
        ],
    ],
];
