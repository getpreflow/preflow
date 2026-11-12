<?php

// The texts of preflow/rbac's core: refusals of staff accounts and roles (StaffAccounts, the
// staff and role commands, an admin's forms), the roles it seeds. An admin's own texts are a group
// of its own (Folio: rbac-admin). A site changes single keys in its own lang/en/rbac.php.
// :patterns is the comma-separated list of the permissions the editor doesn't hold;
// :names the role names that no staff role has.
return [
    'delegation' => [
        'grant' => "You can't grant permissions you don't have: :patterns",
        'account' => "You can't change an account with roles you can't grant.",
        'last' => 'At least one account must keep the right to manage staff and roles.',
        'unknown' => 'Unknown role: :names.',
    ],
    'staff' => [
        'no_sign_in' => "Staff accounts can't sign in on this site yet: the staff guard has no staff record provider.",
        'no_app_url' => 'Set app.url to send sign-in links.',
        'config_email' => 'This e-mail address belongs to a configured admin.',
        'has_password' => 'This account already has a password. Send a reset link instead.',
        'inactive' => 'This account is disabled. Enable it first.',
        'email_taken' => 'Another account already uses this email address.',
        'email_invalid' => 'This is not an e-mail address.',
        'password_short' => 'The password needs at least :min characters.',
        'no_link_page' => 'No page to set a password is configured; use staff:password, or install an admin that provides one.',
        'no_type' => 'This site has no staff accounts: config/auth.php names no user provider of class Preflow\\Auth\\Record\\RecordUserProvider with \'type\' => \'staff\'.',
        'gone' => 'This account no longer exists.',
    ],
    // The roles rbac seeds (population => name), shown in the language of the admin while their
    // label is still the one seeded.
    'roles' => [
        'staff' => ['admin' => 'Administrator'],
    ],
];
