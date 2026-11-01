<?php

// CSRF: every POST, PUT, PATCH and DELETE is checked, with or without a
// session. The browser's origin headers come first (a cross-site request is
// refused with any token); then the session token, or on pages without a
// session the signed token csrf_field() prints (it needs a real app.key).
// Without this file the defaults below apply.
return [
    // Path prefixes without a check. Keep '/--component/' (component actions
    // carry signed tokens). Mark controller routes with #[CsrfExempt('why')]
    // instead; this list is for file routes and paths outside the router.
    // 'exempt' => ['/--component/'],

    // Other origins allowed to post here, as https://host[:port], for example
    // a stage host posting to production. A sibling subdomain isn't trusted
    // without being listed.
    // 'trusted_origins' => [],

    // Extra sign-in forms (members and Folio register their own): a POST there
    // whose session expired goes through when its origin passes. Exact paths,
    // without the locale prefix.
    // 'sign_in_paths' => [],
];
