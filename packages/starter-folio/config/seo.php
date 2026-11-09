<?php

// Titles, descriptions and Open Graph tags for public pages, plus /robots.txt and /sitemap.xml.
// Folio pages and news posts fill them from the record (and its "SEO & sharing" section in the
// editor); a page outside Folio sets its own with {% do seo({title, description}) %}.
return [
    'enabled' => true,
    'name' => 'Preflow Jobs',
    // Per Folio type: the fields the share title, description and image come from (the first one
    // that isn't empty). A page has no description field: staff write one in its SEO section.
    'types' => [
        'page' => ['title' => ['title']],
        'news' => ['title' => ['title'], 'description' => ['summary', 'body'], 'image' => ['image'], 'og_type' => 'article'],
    ],
];
