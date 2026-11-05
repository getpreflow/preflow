<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\SiteCheck;
use Preflow\View\Seo\SchemaRegistry;
use Preflow\View\Seo\SeoConfig;

/**
 * `owner.missing` (notice): SEO is on, but the site names no owner (`owner` in
 * config/seo.php, or a SchemaRegistry owner builder), so the home page's JSON-LD
 * doesn't say who runs the site. One finding when no locale has one, else one per
 * locale without. With SEO off it finds nothing.
 *
 * @since 0.54
 */
final class SiteOwner implements SiteCheck
{
    public function __construct(
        private readonly SeoConfig $seo,
        private readonly SchemaRegistry $schemas,
    ) {}

    public function id(): string
    {
        return 'owner.missing';
    }

    public function check(PageRenderer $renderer): array
    {
        if (!$this->seo->enabled()) {
            return [];
        }

        $missing = array_values(array_filter(
            $renderer->locales(),
            fn (string $locale): bool => trim((string) ($this->schemas->ownerFor($locale, $this->seo->owner)['name'] ?? '')) === '',
        ));

        // Missing everywhere: one finding for the site; else one per locale without.
        $locales = $missing === $renderer->locales() ? [''] : $missing;

        return array_map(fn (string $locale): Finding => new Finding($this->id(), Severity::Notice,
            'The site names no owner (a person or organisation), so search engines don\'t learn who runs it. Set `owner` in config/seo.php.',
            '', $locale), $locales);
    }
}
