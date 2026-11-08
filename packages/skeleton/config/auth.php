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
        // New sessions (and their cookie) only where they are needed: signing in and out, the
        // admin, component actions (/--component). A visitor who brings the session cookie (signed
        // in) resumes it on every page; one without starts none, so the page cache stores public
        // pages for them. Without `paths` every request starts a session and nothing is cached.
        // List a public path here if guests need a session there.
        'paths' => ['/login', '/logout', '/register', '/admin', '/--component'],
        'lifetime' => 7200,
        'cookie' => 'preflow_session',
        'path' => '/',
        'secure' => (bool) (getenv('APP_SECURE') ?: false),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
