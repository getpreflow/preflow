<?php

return [
    // Each key names its driver (sqlite, mysql or json), or 'driver' does: one of those, or the
    // class of your own Preflow\Data\StorageDriver (the entry's other keys go to its constructor).
    'drivers' => [
        'sqlite' => [
            // DATA_PATH moves the database elsewhere (the tests use it).
            'path' => getenv('DATA_PATH') ? getenv('DATA_PATH') . '/app.sqlite' : __DIR__ . '/../storage/data/app.sqlite',
        ],
    ],
    'default' => 'sqlite',
];
