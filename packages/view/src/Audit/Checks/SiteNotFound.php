<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\SiteCheck;

/**
 * The page for an address that doesn't exist, rendered at a made-up path (the
 * internal render keeps it off the not-found list):
 *
 * - `notfound.status` (critical): it doesn't answer 404, so search engines index it as a page;
 * - `notfound.no-home-link` (warning): its 404 page has no link to a home page (any locale's), so a
 *   visitor who landed there has no way on.
 *
 * @since 0.54
 */
final class SiteNotFound implements SiteCheck
{
    public function id(): string
    {
        return 'notfound';
    }

    public function check(PageRenderer $renderer): array
    {
        $page = $renderer->render('/preflow-audit-' . bin2hex(random_bytes(8)));

        if ($page->status !== 404) {
            return [new Finding('notfound.status', Severity::Critical,
                "An address that doesn't exist answers {$page->status} instead of 404, so search engines may index it as a page. Let unknown addresses answer 404.",
                $page->url)];
        }

        if (!self::linksHome($page->dom(), $page->url, $renderer)) {
            return [new Finding('notfound.no-home-link', Severity::Warning,
                'The 404 page has no link to the home page, so a visitor who lands there has no way on. Add one.',
                $page->url)];
        }

        return [];
    }

    private static function linksHome(?\DOMDocument $dom, string $url, PageRenderer $renderer): bool
    {
        if ($dom === null) {
            return false;
        }

        $homes = [Urls::key($renderer->origin() . '/') => true];
        foreach ($renderer->locales() as $locale) {
            if ($locale !== '') {
                $homes[Urls::key($renderer->origin() . '/' . $locale)] = true;
            }
        }

        foreach ($dom->getElementsByTagName('a') as $link) {
            $href = Urls::resolve($link->getAttribute('href'), $url);
            if ($href !== null && isset($homes[Urls::key($href)])) {
                return true;
            }
        }

        return false;
    }
}
