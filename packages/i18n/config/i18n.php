<?php

// preflow/i18n — copy to the app's config/i18n.php to change a default. Core reads it for the locale
// URL rule (Preflow\Core\Http\LocaleUrls), so it works with or without the translator.
return [
    // The default language; with the "prefix" strategy it is the one without a URL prefix. Defaults to app.locale.
    'default' => 'en',
    // Every language the site is available in, the default one included.
    'available' => ['en'],
    // The language a missing text is looked up in. Defaults to the default language.
    'fallback' => 'en',
    // "prefix": the locale is the first URL segment ('/de/about'); "none": no locale in URLs.
    'url_strategy' => 'prefix',
    // Whether the locale middleware remembers the visitor's language in a cookie. Off with the prefix strategy (the URL carries the language), on with "none".
    'cookie' => false,
    // The name of that cookie (letters, digits and !#$%&'*+-.^_`|~).
    'cookie_name' => 'locale',
    // The default language gets a URL prefix too ('/en/about', home '/en'). Needs url_strategy "prefix".
    'prefix_default' => false,
    // A bare page path ('/about') answers 302 to the visitor's language. Needs url_strategy "prefix".
    'redirect_bare' => false,
    // A prefixed language's home ends in a slash ('/de/', not '/de'). Needs url_strategy "prefix". Since 0.78.2.
    'home_slash' => false,
];
