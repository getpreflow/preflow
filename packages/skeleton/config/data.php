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
    ],
    'default' => 'sqlite',
];
