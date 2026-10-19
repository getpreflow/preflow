<?php

// Security headers + Content-Security-Policy. These are the framework defaults;
// override a directive by listing it here (it replaces that directive's sources).
// 'script-src' and 'style-src' automatically get the request nonce appended —
// use nonce="{{ csp_nonce() }}" on any <script>/<style> you write by hand.
// In debug mode the policy is sent as Content-Security-Policy-Report-Only.
return [
    'csp' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'"],
        'img-src' => ["'self'", 'data:'],
        'font-src' => ["'self'"],
        'media-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'frame-src' => ["'self'"],
        'frame-ancestors' => ["'self'"],
        'form-action' => ["'self'"],
        'base-uri' => ["'self'"],
        'object-src' => ["'none'"],
    ],
    'report_only' => false,
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        // set a header to null to stop sending it
    ],
    'hsts' => true, // Strict-Transport-Security, HTTPS requests only
];
