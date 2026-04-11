<?php

return [
    'drivers' => [
        'sqlite' => [
            'driver' => \Preflow\Data\Driver\SqliteDriver::class,
            'path' => __DIR__ . '/../storage/data/app.sqlite',
        ],
        'json' => [
            'driver' => \Preflow\Data\Driver\JsonFileDriver::class,
            'path' => __DIR__ . '/../storage/data',
        ],
        // 'mysql' => [
        //     'driver' => \Preflow\Data\Driver\MysqlDriver::class,
        //     'host' => getenv('DB_HOST') ?: '127.0.0.1',
        //     'port' => getenv('DB_PORT') ?: '3306',
        //     'database' => getenv('DB_NAME') ?: '',
        //     'username' => getenv('DB_USER') ?: 'root',
        //     'password' => getenv('DB_PASS') ?: '',
        // ],
    ],
    'default' => getenv('DB_DRIVER') ?: 'sqlite',
    'models_path' => __DIR__ . '/models',
];
