<?php

// JSON APIs. Paths under these prefixes answer errors as JSON and are never page-cached.
return [
    'prefixes' => ['/api'],

    // CORS is off while 'origins' is empty. Browsers refuse '*' together with credentials.
    'cors' => [
        'origins' => [],
        // 'origins' => ['https://app.example.com'],
        // 'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        // 'headers' => ['Authorization', 'Content-Type', 'Accept'],
        // 'max_age' => 600,
    ],
];
