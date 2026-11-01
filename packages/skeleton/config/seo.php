<?php

// SEO: robots, theme-color, Open Graph and Twitter card tags plus JSON-LD on
// public HTML pages (only tags a page doesn't already have), robots.txt,
// sitemap.xml, security.txt and ETags. Noindex, preview and members-only pages
// get no cards, structured data or sitemap entry. Off until 'enabled' => true.
return [
    'enabled' => false,

    // Site name for og:site_name and JSON-LD; null reads it from Folio's `site`
    // singleton through 'site_fields', else app.name.
    // 'name' => null,
    // 'site_fields' => ['name' => 'name', 'description' => 'meta_description'],

    // Who runs the site, as a schema.org Person or Organization.
    // 'owner' => [
    //     'type' => 'Organization',           // or 'Person'
    //     'name' => 'Example Ltd',
    //     'url' => 'https://example.com',
    //     'logo' => 'https://example.com/logo.png',
    //     'same_as' => ['https://github.com/example'],
    // ],

    // Per Folio type: fields the title, description and share image are read
    // from (first non-empty wins), and the Open Graph type.
    // 'types' => [
    //     'post' => ['title' => ['title'], 'description' => ['lead'], 'image' => ['cover'], 'og_type' => 'article'],
    // ],

    // Types in the sitemap; null lists every public routed type.
    // 'listed_types' => null,

    // robots.txt: paths kept from crawlers, whether AI training crawlers are
    // welcome, and extra lines appended as they are.
    // 'robots' => [
    //     'enabled' => true,
    //     'disallow' => ['/--component/', '/*/--component/'],
    //     'ai_training' => true,
    //     'extra' => [],
    // ],

    // Whether /sitemap.xml is served.
    // 'sitemap' => true,

    // /.well-known/security.txt, served only with a contact (mailto: or https: URL).
    // 'security_txt' => ['contact' => null, 'policy' => null],

    // Browser UI colour: '#hex', or ['light' => '#hex', 'dark' => '#hex'].
    // 'theme_color' => null,

    // ETag and 304 Not Modified for public pages.
    // 'etag' => true,
];
