<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;

/**
 * `canonical.foreign-host` (warning): the page's canonical URL is on another host
 * than the site's (app.url), e.g. a staging or old domain.
 *
 * @since 0.54
 */
final class CanonicalForeignHost implements PageCheck
{
    private readonly string $host;

    /** @param string $origin the site's scheme and host (PageRenderer::origin()) */
    public function __construct(private readonly AuditRegistry $registry, string $origin)
    {
        $this->host = Urls::host($origin);
    }

    public function id(): string
    {
        return 'canonical.foreign-host';
    }

    public function check(RenderedPage $page): array
    {
        $dom = $page->dom();
        if ($dom === null) {
            return [];
        }
        foreach (Html::links($dom, 'canonical') as $link) {
            $canonical = Urls::resolve($link->getAttribute('href'), $page->url);
            if ($canonical === null) {
                continue;
            }
            $host = Urls::host($canonical);
            if ($host === $this->host) {
                return [];
            }

            return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Warning,
                "The canonical URL {$canonical} is on {$host}, not on the site's host {$this->host}; point it at this site's own address.")];
        }

        return [];
    }
}
