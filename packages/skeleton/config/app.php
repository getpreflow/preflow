<?php

return [
    'name' => getenv('APP_NAME') ?: 'Preflow App',
    // 0 = production, 1 = development, 2 = verbose (forces dev panels for all components)
    'debug' => (int) (getenv('APP_DEBUG') ?: 0),
    'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
    'locale' => getenv('APP_LOCALE') ?: 'en',
    'key' => getenv('APP_KEY') ?: '',
    // The site's address with its scheme (https://example.com): CSRF origin checks, absolute
    // links and the page cache's host.
    'url' => getenv('APP_URL') ?: 'http://localhost:8080',
    // Template engine: 'twig' or 'blade'
    'engine' => getenv('APP_ENGINE') ?: 'twig',
];
