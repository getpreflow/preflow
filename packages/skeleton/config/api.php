<?php

// JSON APIs. Everything is optional; without this file '/api' is the API prefix and CORS is off.
return [
    // Paths under these prefixes (whole segments) answer errors as JSON and are never page-cached.
    // 'prefixes' => ['/api'],

    // CORS is off while 'origins' is empty. Browsers refuse '*' together with credentials.
    'cors' => [
        'origins' => [],
        // 'origins' => ['https://app.example.com'],
        // 'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        // 'headers' => ['Authorization', 'Content-Type', 'Accept'],
        // 'expose' => [],
        // 'max_age' => 600,
        // 'credentials' => false,
    ],
];
