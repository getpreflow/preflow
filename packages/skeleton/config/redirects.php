<?php

// Redirects and the not-found list: a renamed or removed page keeps answering
// with a redirect, and addresses visitors hit in vain are listed in Folio
// (Settings -> Redirects) so you can point them somewhere. Without this file
// the defaults below apply.
return [
    // Keep a list of addresses that answered 404 (path, language and count
    // only; no IP, user agent or referrer). Redirects work either way.
    // 'not_found' => true,

    // How many addresses the list keeps; the least recently hit go first.
    // 'not_found_limit' => 1000,

    // More scanner noise to leave out of the list (WordPress probes, dotfiles
    // and common backup or script extensions are already skipped). An entry
    // starting with '.' is an extension, anything else a path prefix.
    // 'ignore' => ['/legacy/', '.json'],
];
