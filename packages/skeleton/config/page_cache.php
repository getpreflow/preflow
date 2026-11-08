<?php

// The page cache: visitors who are not signed in get finished pages, refreshed whenever
// content they show is written through the data layer. Off while app.debug is on and in the
// console. Pages are cached for the host of app.url (and `hosts`); without it nothing is.
// Every key below is optional; the values shown are the defaults.
return [
    'enabled' => true,

    // The safety maximum age (seconds): an older page is still served once while a fresh one
    // is built. 0 turns it off. Shorter times per page or per dependency belong in templates
    // ({% expires %}, {% freshness 'timed' every '12h' %}), not here.
    // 'max_age' => 14400,

    // Pages with a query string (search, filters) are cached too; these parameters are left
    // out of the key.
    // 'cache_query' => true,
    // 'ignore_query' => ['utm_*', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'],

    // Request headers an htmx fragment's key includes (add the ones your responses Vary on).
    // 'vary_headers' => ['HX-Request', 'HX-Target'],

    // Cookies that mean "signed in" (the request bypasses the cache), added to preflow/auth's
    // marker cookie and every guard's remember-me cookie.
    // 'signed_in_cookies' => [],

    // More host names to cache besides the host of app.url.
    // 'hosts' => [],

    // Send X-Page-Cache-Skip with the reason a miss was not stored (for audits with curl -I).
    // 'explain' => false,

    // Render a page a second time as another anonymous visitor before storing it, and log and
    // skip pages that differ outside their {% live %} parts: 'first' (the first store of each
    // new page, one extra render), 'always' (tests, staging: every store renders twice), 'off'.
    // 'verify' => 'first',
];
