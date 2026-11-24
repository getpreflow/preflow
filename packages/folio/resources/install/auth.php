<?php

// Written by `php preflow folio:install`; yours to change.
return [
    // Folio signs staff in through the default guard (config/folio.php 'guard' could name another).
    'default_guard' => 'staff',

    'guards' => [
        // Staff sign in to Folio at its /login page. Their roles (preflow/rbac, seeded from
        // config/roles.php) decide what each staff account may do.
        'staff' => [
            'class' => \Preflow\Auth\SessionGuard::class,
            'provider' => 'staff',
            'population' => 'staff',
        ],
    ],

    'providers' => [
        // Staff accounts are rbac's records (type staff, table staff, made by `php preflow rbac:install`),
        // created with `php preflow staff:create`, so no password hash is kept in a config file.
        'staff' => [
            'class' => \Preflow\Auth\Record\RecordUserProvider::class,
            'type' => 'staff',
            'active' => 'active',
        ],
    ],

    'password_hasher' => \Preflow\Auth\NativePasswordHasher::class,

    'session' => [
        // Sessions start only here, so public pages stay cookie-free (and the page cache can keep
        // them). Add the paths of other signed-in areas when you make one.
        'paths' => ['__PATH__'],
        'lifetime' => 7200,
        'cookie' => 'preflow_session',
        'path' => '/',
        'secure' => (bool) (getenv('APP_SECURE') ?: false),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
