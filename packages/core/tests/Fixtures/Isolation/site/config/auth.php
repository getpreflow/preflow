<?php

return [
    'default_guard' => 'session',
    'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users']],
    'providers' => ['users' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
        ['id' => 'member', 'email' => 'member@site.test', 'password_hash' => '', 'roles' => []],
    ]]],
    'session' => ['cookie' => 'isolation', 'secure' => false],
];
