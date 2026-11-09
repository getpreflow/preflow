<?php

// Sizes for media_image(id, preset): the media library makes these widths of an uploaded image,
// and the browser picks the closest one for the space it shows the image in.
return [
    'presets' => [
        'hero' => ['widths' => [480, 960], 'sizes' => '(min-width: 48rem) 20rem, 100vw', 'priority' => true],
        'wide' => ['widths' => [480, 960], 'sizes' => '(min-width: 52rem) 50rem, 100vw'],
        'card' => ['widths' => [320, 640], 'sizes' => '(min-width: 36rem) 15rem, 100vw'],
    ],
];
