<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** What only the site knows: whether a page answers at an address. */
interface PageExists
{
    /** Whether a page holds the path in the entry locale ('' is the site's default language). */
    public function exists(string $locale, string $path): bool;
}
