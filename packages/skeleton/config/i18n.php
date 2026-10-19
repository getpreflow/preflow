<?php

return [
    'default' => 'en',
    'available' => ['en', 'de'],
    'fallback' => 'en',
    'url_strategy' => 'prefix',
    // No locale cookie: with the prefix strategy the URL carries the locale.
    'cookie' => false,
];
