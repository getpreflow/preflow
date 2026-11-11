<?php

return [
    'default_guard' => 'employers',
    'api_guard' => 'token',

    'guards' => [
        // Employers sign in with the form at /employer/login. Their population is not staff, so
        // preflow/folio refuses to boot on this guard: adding Folio needs a staff guard of its own
        // (as preflow/starter-folio has), never the employers'. For permissions they count as members.
        'employers' => [
            'class' => Preflow\Auth\SessionGuard::class,
            'provider' => 'users',
            'population' => 'employers',
            'login' => '/employer/login',
        ],
        // API clients send "Authorization: Bearer <token>" (preflow auth:token).
        'token' => [
            'class' => Preflow\Auth\TokenGuard::class,
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'class' => Preflow\Auth\DataManagerUserProvider::class,
            'model' => App\Models\User::class,
        ],
    ],

    'password_hasher' => Preflow\Auth\NativePasswordHasher::class,

    'session' => [
        // Sessions start only here. /--component is left out on purpose: a visitor who searches
        // must not get a session cookie, or every later page would skip the page cache.
        'paths' => ['/employer'],
        'lifetime' => 7200,
        'cookie' => 'preflow_session',
        'path' => '/',
        'secure' => (bool) (getenv('APP_SECURE') ?: false),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
