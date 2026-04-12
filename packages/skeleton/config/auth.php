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
        'lifetime' => 7200,
        'cookie' => 'preflow_session',
        'path' => '/',
        'secure' => (bool) (getenv('APP_SECURE') ?: false),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
