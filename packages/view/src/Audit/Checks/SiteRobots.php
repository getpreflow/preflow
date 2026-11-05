<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\SiteCheck;
use Preflow\View\Seo\SeoConfig;

/**
 * `robots.missing` (warning): SEO is on, but neither the app nor a file in
 * public/ answers /robots.txt. With SEO off it finds nothing.
 *
 * @since 0.54
 */
final class SiteRobots implements SiteCheck
{
    public function __construct(
        private readonly SeoConfig $seo,
        private readonly ?string $publicDir = null,
    ) {}

    public function id(): string
    {
        return 'robots.missing';
    }

    public function check(PageRenderer $renderer): array
    {
        if (!$this->seo->enabled() || SiteFiles::read($renderer, $this->publicDir, '/robots.txt') !== null) {
            return [];
        }

        return [new Finding($this->id(), Severity::Warning,
            "/robots.txt doesn't answer, so crawlers get no rules and no pointer to the sitemap. Turn robots.txt on in config/seo.php or put one in public/.")];
    }
}
