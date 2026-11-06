<?php

// GEO (generative engine optimisation): Markdown views of public pages for AI
// assistants — /about.md, /index.md, or a page URL asked for with
// Accept: text/markdown — plus a rel="alternate" link on every public HTML page.
// Markdown is always the anonymous visitor's view. Off until 'enabled' => true.
return [
    'enabled' => false,

    // Site name and summary for llms.txt; null reads them from Folio's `site`
    // singleton through 'site_fields'.
    // 'name' => null,
    // 'summary' => null,
    // 'site_fields' => ['name' => 'name', 'summary' => 'meta_description'],

    // Folio types listed in llms.txt, and a boolean field that keeps a record out.
    // 'types' => ['page'],
    // 'noindex_field' => null,

    // Whether /llms-full.txt (all listed pages' Markdown in one file) is served.
    // 'full' => true,
    // Above this size (MB) `preflow seo:audit` reports llms-full.txt as too large to be useful.
    // 'full_max_mb' => 5,

    // Page paths without a Markdown view (exact paths, e.g. '/thanks').
    // 'exclude' => [],
];
