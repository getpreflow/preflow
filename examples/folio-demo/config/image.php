<?php

// Responsive image presets, used by responsive_image() and folio_image().
// Their widths also decide which variants the asset library generates.
return [
    'presets' => [
        'hero' => ['widths' => [640, 1280, 1920], 'sizes' => '100vw', 'loading' => 'eager'],
        'card' => ['widths' => [320, 640], 'sizes' => '(min-width: 48rem) 33vw, 100vw'],
    ],
];
