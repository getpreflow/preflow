<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A list cell shown as a link: what a #[ListColumn] method returns instead of a string. The
 * URL is relative to the admin prefix ('member/3/edit') or a site path ('/shop/order?id=3').
 * Anything else (a scheme, a host, '//', a backslash, a control or whitespace character) is
 * not a link: the escaped label is shown as plain text.
 */
final class Link
{
    public function __construct(
        public readonly string $label,
        public readonly string $url,
    ) {}

    /** The href, or null when the URL is not an admin-relative or same-site path. */
    public function href(string $adminPrefix): ?string
    {
        $url = $this->url;
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }
        if ($url[0] === '/') {
            return ($url[1] ?? '') === '/' ? null : $url;
        }
        if (preg_match('/^[A-Za-z0-9_]/', $url) !== 1) {
            return null;
        }
        // A colon before the first / ? or # is a scheme.
        if (preg_match('/^[^\/?#]*:/', $url) === 1) {
            return null;
        }

        return rtrim($adminPrefix, '/') . '/' . $url;
    }
}
