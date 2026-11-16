<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/**
 * Optional, beside SiteAddresses: a site whose bare addresses are always sent to a language first
 * (config/i18n.php prefix_default and redirect_bare). A bare target such as "/help" is then never
 * answered by another entry, because the language redirect runs before the redirect entries, so
 * the redirect rules' no-chains check leaves it alone. A site that does not implement this keeps
 * the rules as they were.
 *
 * @since 0.78.2
 */
interface LanguageRedirectedAddresses
{
    /**
     * Whether a URL on this site is a bare address (no language segment) that the site's language
     * redirect always runs on first; false for a prefixed one, another host, or when the site has no
     * such redirect.
     */
    public function isLanguageRedirected(string $url, string $host = ''): bool;
}
