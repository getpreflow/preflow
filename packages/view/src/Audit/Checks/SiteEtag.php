<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\SiteCheck;
use Preflow\View\Seo\SeoConfig;

/**
 * Conditional requests on the home page, when SEO gives pages an ETag (SEO on, `etag` not off):
 *
 * - `etag.cookie` (notice): the page sets a cookie (such as the locale cookie), so it gets no ETag and
 *   browsers can't revalidate it;
 * - `etag.unstable` (notice): two renders in a row give different ETags (per-request content such as a
 *   timing token), so a browser never gets a 304;
 * - `etag.no-304` (notice): asked with its own ETag, the page doesn't answer 304.
 *
 * A page without an ETag for another reason (private, not 200 HTML) finds nothing here.
 *
 * @since 0.54
 */
final class SiteEtag implements SiteCheck
{
    public function __construct(private readonly SeoConfig $seo) {}

    public function id(): string
    {
        return 'etag';
    }

    public function check(PageRenderer $renderer): array
    {
        if (!$this->seo->enabled() || !$this->seo->etag) {
            return [];
        }

        $first = $renderer->render('/');
        if ($first->status !== 200) {
            return [];
        }
        $etag = $first->header('ETag');
        if ($etag === '') {
            $cookie = $first->header('Set-Cookie');
            if ($cookie === '') {
                return [];
            }
            $name = trim(explode('=', $cookie, 2)[0]);

            return [new Finding('etag.cookie', Severity::Notice,
                "The home page sets a cookie ({$name}) for visitors who don't have it yet, crawlers among them, so it can't be cached or validated for them: it gets no ETag and no 304. Set cookies only when a visitor chooses something (with the prefix URL strategy the i18n locale cookie isn't needed: 'cookie' => false in config/i18n.php).",
                $first->url)];
        }

        $second = $renderer->render('/');
        if ($second->header('ETag') !== $etag) {
            return [new Finding('etag.unstable', Severity::Notice,
                'Two renders of the home page in a row give different ETags, so browsers never get a 304. Something in the page changes on every request (a token, a timestamp, a random value); move it out of the page or into a script.',
                $first->url)];
        }

        $conditional = $renderer->render('/', ['If-None-Match' => $etag]);
        if ($conditional->status !== 304) {
            return [new Finding('etag.no-304', Severity::Notice,
                "Asked with its own ETag, the home page answers {$conditional->status} instead of 304, so browsers download it again on every visit.",
                $first->url)];
        }

        return [];
    }
}
