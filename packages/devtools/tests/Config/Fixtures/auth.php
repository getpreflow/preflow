<?php

return [
    'default_guard' => 'members',

    'guards' => [
        // Members sign in at /login.
        'members' => [
            'class' => Preflow\Auth\SessionGuard::class,
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'class' => Preflow\Auth\DataManagerUserProvider::class,
            'model' => App\Models\User::class,
        ],
    ],

    'session' => [
        // Sessions start only here.
        'paths' => ['/account'],
        'lifetime' => 7200,
        'secure' => (bool) (getenv('APP_SECURE') ?: false),
    ],
];
