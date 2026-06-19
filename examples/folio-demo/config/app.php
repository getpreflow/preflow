<?php

declare(strict_types=1);

return [
    'name' => 'Folio Demo',
    // 0 = production (clean UI, no dev panels). Bump to 1 to see dev tooling.
    'debug' => (int) (getenv('APP_DEBUG') ?: 0),
    'engine' => 'twig',
    'key' => getenv('APP_KEY') ?: 'folio-demo-not-a-secret',
];
