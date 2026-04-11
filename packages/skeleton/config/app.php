<?php

return [
    'name' => getenv('APP_NAME') ?: 'Preflow App',
    // 0 = production, 1 = development, 2 = verbose (forces dev panels for all components)
    'debug' => (int) (getenv('APP_DEBUG') ?: 0),
    'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
    'locale' => getenv('APP_LOCALE') ?: 'en',
    'key' => getenv('APP_KEY') ?: '',
    // Template engine: 'twig' or 'blade'
    'engine' => getenv('APP_ENGINE') ?: 'twig',
];
