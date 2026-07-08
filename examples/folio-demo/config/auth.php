<?php

return [
    'default_guard' => 'session',

    'guards' => [
        'session' => ['class' => Preflow\Auth\SessionGuard::class, 'provider' => 'config'],
    ],

    'providers' => [
        'config' => [
            'class' => Preflow\Auth\ConfigUserProvider::class,
            'users' => [
                [
                    'email' => 'admin@folio.test',
                    // Hash of the plaintext password below, recomputed each boot
                    // (demo-only; a real app stores a fixed hash).
                    'password_hash' => password_hash(getenv('FOLIO_ADMIN_PASSWORD') ?: 'folio-admin', PASSWORD_DEFAULT),
                    'roles' => ['admin'],
                ],
            ],
        ],
    ],

    'password_hasher' => Preflow\Auth\NativePasswordHasher::class,

    'session' => [
        'lifetime' => 7200,
        'cookie' => 'folio_session',
        'path' => '/',
        'secure' => (bool) (getenv('APP_SECURE') ?: false),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
