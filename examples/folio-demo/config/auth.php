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
                    // Fixed hash of the demo password "folio-admin". Hashing at boot would
                    // cost ~200 ms per request (bcrypt is slow on purpose), so only an
                    // explicit FOLIO_ADMIN_PASSWORD override is hashed here.
                    'password_hash' => getenv('FOLIO_ADMIN_PASSWORD')
                        ? password_hash((string) getenv('FOLIO_ADMIN_PASSWORD'), PASSWORD_DEFAULT)
                        : '$2y$12$nKPo.49ITdlLr2shpSQ4YetSvwEFR8NJQYoWgvUTfusHiCd45rUiy',
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
