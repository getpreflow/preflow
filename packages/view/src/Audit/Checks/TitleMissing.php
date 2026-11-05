<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;

/**
 * `title.missing` (critical): the page has no <title>, or an empty one.
 *
 * @since 0.54
 */
final class TitleMissing implements PageCheck
{
    public function __construct(private readonly AuditRegistry $registry) {}

    public function id(): string
    {
        return 'title.missing';
    }

    public function check(RenderedPage $page): array
    {
        $dom = $page->dom();
        if ($dom === null || (Html::title($dom) ?? '') !== '') {
            return [];
        }

        return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Critical,
            'The page has no title, so search results show none; give it a <title> that names what it is about.')];
    }
}
