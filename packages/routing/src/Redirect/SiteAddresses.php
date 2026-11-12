<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** What only the site knows: how its addresses read (locale prefixes, its own host). */
interface SiteAddresses
{
    /**
     * The entry locale and path a URL on this site lands on (a path, or an absolute URL with the
     * site's host or $host); null for another host or a path RedirectPath::normalize() rejects.
     *
     * @return array{string, string}|null
     */
    public function address(string $url, string $host = ''): ?array;

    /** The locale a request for an address of $locale has ('' on a site without locale prefixes: its default). */
    public function lookupLocale(string $locale): string;
}
