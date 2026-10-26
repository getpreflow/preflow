<?php

// Folio's admin texts and its mails to its accounts (invite, reset), in each user's interface
// language. A site changes single phrases in its own lang/{locale}/folio.php. In the mails,
// {name}, {site} and {link} are filled in.
return [
    'common' => [
        'save' => 'Save',
    ],
    'layout' => [
        'language' => 'Language',
        'theme' => 'Theme',
        'theme_system' => 'System theme',
        'theme_light' => 'Light theme',
        'theme_dark' => 'Dark theme',
        'your_account' => 'Your account',
        'log_out' => 'Log out',
    ],
    'auth' => [
        'sign_in' => 'Sign in',
    ],
    'account' => [
        'language_help' => "Folio's language for you. Other users keep their own.",
    ],
    'locale' => [
        'not_offered' => 'This language is not offered.',
    ],
    'mail' => [
        'invite' => [
            'subject' => 'Your account for {site}',
            'body' => "Hi {name},\n\nan account was set up for you to edit {site} in Folio. Choose your password here: {link}\n\nThe link works for 7 days.\n\nKind regards\n{site}",
        ],
        'reset' => [
            'subject' => 'Reset your Folio password for {site}',
            'body' => "Hi {name},\n\nset a new password for {site} here: {link}\n\nThe link works for one hour. If you didn't ask for this, ignore this mail.\n\nKind regards\n{site}",
        ],
    ],
];
