<?php

return [
    // Folio signs staff in through the default guard (config/folio.php 'guard' could name
    // another), so staff get it. Employers sign in on a guard of their own: a session of one never
    // opens the other's area.
    'default_guard' => 'staff',
    'api_guard' => 'token',

    'guards' => [
        // Staff sign in to Folio at /folio/login. Folio marks this guard as the staff population,
        // so the roles (rbac, seeded from config/roles.php) decide what each staff account may do.
        'staff' => [
            'class' => Preflow\Auth\SessionGuard::class,
            'provider' => 'staff',
        ],
        // Employers sign in with the form at /employer/login. As members they never reach Folio
        // (it asks the staff guard); config/roles.php gives their employer role job.publish only.
        'employers' => [
            'class' => Preflow\Auth\SessionGuard::class,
            'provider' => 'users',
            'population' => 'members',
            'login' => '/employer/login',
        ],
        // API clients send "Authorization: Bearer <token>" (preflow auth:token).
        'token' => [
            'class' => Preflow\Auth\TokenGuard::class,
            'provider' => 'users',
        ],
    ],

    'providers' => [
        // Staff accounts are rbac's records (type staff, table staff, made by `preflow rbac:install`),
        // created by the demo seed or `preflow staff:create`, so no password hash ships in a config file.
        'staff' => [
            'class' => Preflow\Auth\Record\RecordUserProvider::class,
            'type' => 'staff',
            'active' => 'active',
        ],
        'users' => [
            'class' => Preflow\Auth\DataManagerUserProvider::class,
            'model' => App\Models\User::class,
        ],
    ],

    'password_hasher' => Preflow\Auth\NativePasswordHasher::class,

    'session' => [
        // Sessions start only here. /--component is left out on purpose: a visitor who searches
        // must not get a session cookie, or every later page would skip the page cache.
        'paths' => ['/employer', '/folio'],
        'lifetime' => 7200,
        'cookie' => 'preflow_session',
        'path' => '/',
        'secure' => (bool) (getenv('APP_SECURE') ?: false),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
