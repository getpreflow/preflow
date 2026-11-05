<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;

/**
 * `canonical.missing` (notice): the page names no canonical URL (<link rel="canonical">).
 *
 * @since 0.54
 */
final class CanonicalMissing implements PageCheck
{
    public function __construct(private readonly AuditRegistry $registry) {}

    public function id(): string
    {
        return 'canonical.missing';
    }

    public function check(RenderedPage $page): array
    {
        $dom = $page->dom();
        if ($dom === null) {
            return [];
        }
        foreach (Html::links($dom, 'canonical') as $link) {
            if (trim($link->getAttribute('href')) !== '') {
                return [];
            }
        }

        return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Notice,
            'The page names no canonical URL; add <link rel="canonical"> so search engines know its main address.')];
    }
}
