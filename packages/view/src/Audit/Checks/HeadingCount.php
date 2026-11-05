<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;

/**
 * `h1.count` (notice): the page has no <h1>, or more than one.
 *
 * @since 0.54
 */
final class HeadingCount implements PageCheck
{
    public function __construct(private readonly AuditRegistry $registry) {}

    public function id(): string
    {
        return 'h1.count';
    }

    public function check(RenderedPage $page): array
    {
        $count = $page->dom()?->getElementsByTagName('h1')->length;
        if ($count === null || $count === 1) {
            return [];
        }

        return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Notice, $count === 0
            ? 'The page has no <h1>; give it one main heading that says what the page is about.'
            : "The page has {$count} <h1> headings; keep one main heading and make the others <h2> or lower.")];
    }
}
