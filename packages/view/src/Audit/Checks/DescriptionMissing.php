<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;

/**
 * `description.missing` (warning): the page has no meta description, or an empty one.
 *
 * @since 0.54
 */
final class DescriptionMissing implements PageCheck
{
    public function __construct(private readonly AuditRegistry $registry) {}

    public function id(): string
    {
        return 'description.missing';
    }

    public function check(RenderedPage $page): array
    {
        $dom = $page->dom();
        if ($dom === null || (Html::meta($dom, 'name', 'description') ?? '') !== '') {
            return [];
        }

        return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Warning,
            'The page has no meta description, so search engines pick a snippet themselves; add a short summary of the page.')];
    }
}
