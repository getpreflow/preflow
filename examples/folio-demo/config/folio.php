<?php

declare(strict_types=1);

return [
    'path' => '/folio',
    // Demo blocks live under examples/folio-demo/app/Blocks (namespace
    // Preflow\Examples\FolioDemo\Blocks); an app would put component blocks
    // in app/Components and data-only blocks in app/Blocks instead.
    'blocks' => [
        'classes' => [
            \Preflow\Examples\FolioDemo\Blocks\Hero\Hero::class,
            \Preflow\Examples\FolioDemo\Blocks\Link::class,
        ],
    ],
];
