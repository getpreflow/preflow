<?php

return [
    'drivers' => [
        'sqlite' => [
            'driver' => \Preflow\Data\Driver\SqliteDriver::class,
            // DATA_PATH moves the database elsewhere (the tests use it).
            'path' => getenv('DATA_PATH') ? getenv('DATA_PATH') . '/app.sqlite' : __DIR__ . '/../storage/data/app.sqlite',
        ],
    ],
    'default' => 'sqlite',
];
