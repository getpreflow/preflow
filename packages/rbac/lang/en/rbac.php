<?php

// Admin texts of preflow/rbac (Folio: delegation, staff accounts, the roles screen). A site changes
// single keys in its own lang/en/rbac.php.
// :patterns is the comma-separated list of the permissions the editor doesn't hold;
// :names the role names that no staff role has; :email a staff account's address.
return [
    'delegation' => [
        'grant' => "You can't grant permissions you don't have: :patterns",
        'account' => "You can't change an account with roles you can't grant.",
        'last' => 'At least one account must keep the right to manage staff and roles.',
        'unknown' => 'Unknown role: :names.',
        'own' => 'You would lose the right to edit roles.',
        'last_role' => 'No staff role would be left that may edit roles.',
        'duplicate' => 'A :population role named :name already exists.',
    ],
    'staff' => [
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
        'email_taken' => 'Another account already uses this email address.',
    ],
    // The Account panel of a staff account and its actions.
    'account' => [
        'title' => 'Account',
        'active' => 'Active',
        'disabled' => 'Disabled',
        'no_password' => 'No password yet',
        'disabled_help' => "Can't sign in. Its sessions and links ended when it was disabled.",
        'invited_help' => "Can't sign in until the invitation link is used to set a password.",
        'active_help' => 'Signs in with a password of its own.',
        'disable_confirm' => 'Disable :email? Its sessions and links end at once.',
    ],
    'actions' => [
        'invite' => 'Send invitation',
        'reset' => 'Send reset link',
        'disable' => 'Disable',
        'enable' => 'Enable',
    ],
    // The permissions of a role (the rbac_permissions field). :pattern is "*"; :verb a column's
    // verb, :type a content type's label; :names the unknown permissions.
    'matrix' => [
        'everything' => 'Everything',
        'everything_hint' => 'Grants every permission, now and in future extensions (:pattern)',
        'unknown' => 'Unknown, removed on save: :names',
        'type' => 'Type',
        'all' => 'All',
        'none' => 'No :verb permission for :type',
        'other' => 'Other permissions',
        'other_group' => 'Other',
        'denied' => "You can't grant this",
    ],
    // A user's roles (the rbac_roles field). :role is a role's name.
    'roles_field' => [
        'not_defined' => ':role (not defined)',
        'no_population' => 'No population: give the type "users" or the field "population".',
    ],
    // The heading of rbac's permissions on the roles screen.
    'extension' => ['label' => 'Roles'],
    // The roles rbac seeds (population => name), shown in the language of the admin while their
    // label is still the one seeded.
    'roles' => [
        'staff' => ['admin' => 'Administrator'],
    ],
    // types/fields/options: the labels of models/staff.json and models/rbac_role.json (Preflow\Data\ModelLabelKeys).
    'types' => [
        'staff' => ['label' => 'Staff', 'singular' => 'Staff', 'empty' => 'No staff yet.'],
        'rbac_role' => ['label' => 'Roles', 'singular' => 'Role', 'empty' => 'No roles yet.'],
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
