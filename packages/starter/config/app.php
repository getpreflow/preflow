<?php

return [
    'name' => getenv('APP_NAME') ?: 'Preflow Jobs',
    // 0 = production, 1 = development, 2 = verbose (forces dev panels for all components)
    'debug' => (int) (getenv('APP_DEBUG') ?: 0),
    'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
    'locale' => getenv('APP_LOCALE') ?: 'en',
    'key' => getenv('APP_KEY') ?: '',
    // The site's address with its scheme (https://example.com): CSRF origin checks, absolute
    // links and the page cache's host. Without it the page cache caches nothing and says so.
    'url' => getenv('APP_URL') ?: '',
    // Template engine: 'twig', 'blade' or the class of a Preflow\View\TemplateEngineFactory.
    // (A hypermedia driver other than htmx: 'hypermedia' => the class of a Preflow\Htmx\HypermediaDriver.)
    'engine' => getenv('APP_ENGINE') ?: 'twig',
];
