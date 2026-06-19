<?php

declare(strict_types=1);

return [
    'drivers' => [
        'json' => [
            'driver' => \Preflow\Data\Driver\JsonFileDriver::class,
            'path' => __DIR__ . '/../storage/data',
        ],
    ],
    'default' => 'json',
    'models_path' => __DIR__ . '/models',
];
