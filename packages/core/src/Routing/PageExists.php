<?php

declare(strict_types=1);

namespace Preflow\Core\Routing;

/**
 * Whether a page answers at an address. Anything that knows can implement it: a router adapter
 * (preflow/routing's Router answers for its routes), or a site's or package's own lookup bound in
 * the container (Folio binds one for its pages). The bare-path redirect (config/i18n.php
 * redirect_bare) asks it, so an unknown address stays a 404 instead of a redirect to one.
 */
interface PageExists
{
    /** Whether a page holds $path (without a locale prefix) in $locale ('' is the site's default language). */
    public function exists(string $locale, string $path): bool;
}
