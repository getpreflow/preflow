<?php

return [
    'default_guard' => 'session',

    'guards' => [
        'session' => [
            'class' => Preflow\Auth\SessionGuard::class,
            'provider' => 'data_manager',
        ],
        'token' => [
            'class' => Preflow\Auth\TokenGuard::class,
            'provider' => 'data_manager',
        ],
    ],

    'providers' => [
        'data_manager' => [
            'class' => Preflow\Auth\DataManagerUserProvider::class,
            'model' => App\Models\User::class,
        ],
    ],

    'password_hasher' => Preflow\Auth\NativePasswordHasher::class,

    'session' => [
        // Sessions (and their cookie) only where they are needed: signing in and out, the admin,
        // component actions (/--component). Public pages start none, so the page cache stores
        // them; without `paths` every request starts a session and nothing is cached. Signed-in
        // visitors still get live pages (the preflow_auth cookie bypasses the cache), but a
        // public page doesn't know who they are: show personal things on these paths only.
        'paths' => ['/login', '/logout', '/register', '/admin', '/--component'],
        'lifetime' => 7200,
        'cookie' => 'preflow_session',
        'path' => '/',
        'secure' => (bool) (getenv('APP_SECURE') ?: false),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
