<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\SiteCheck;
use Preflow\View\Seo\SeoConfig;
use Preflow\View\Seo\SeoSite;

/**
 * `share-image.missing` (notice): SEO is on, but the site gives no default share
 * image (SeoSite defaults; Folio's site settings), so a page without its own shows
 * no picture when it is shared. One finding when no locale has one, else one per
 * locale without. With SEO off it finds nothing.
 *
 * @since 0.54
 */
final class SiteShareImage implements SiteCheck
{
    public function __construct(
        private readonly SeoConfig $seo,
        private readonly SeoSite $site,
    ) {}

    public function id(): string
    {
        return 'share-image.missing';
    }

    public function check(PageRenderer $renderer): array
    {
        if (!$this->seo->enabled()) {
            return [];
        }

        $missing = array_values(array_filter(
            $renderer->locales(),
            fn (string $locale): bool => trim((string) ($this->site->defaultsFor($locale)?->image['url'] ?? '')) === '',
        ));

        // Missing everywhere: one finding for the site; else one per locale without.
        $locales = $missing === $renderer->locales() ? [''] : $missing;

        return array_map(fn (string $locale): Finding => new Finding($this->id(), Severity::Notice,
            'The site has no default share image, so pages without their own show no picture when shared. Set one in the site settings.',
            '', $locale), $locales);
    }
}
