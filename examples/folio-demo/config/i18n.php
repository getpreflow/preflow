<?php

// Content languages: English (default, served at /) and German (/de/…).
// Fields marked "translatable" in config/models/*.json hold one value per language.
return [
    'default' => 'en',
    'available' => ['en', 'de'],
    'fallback' => 'en',
    'url_strategy' => 'prefix',
    'cookie' => false,
];
