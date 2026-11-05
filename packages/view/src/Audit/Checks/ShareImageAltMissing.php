<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;

/**
 * `og.image-alt-missing` (notice): the page has a share image (og:image) but no
 * og:image:alt describing it.
 *
 * @since 0.54
 */
final class ShareImageAltMissing implements PageCheck
{
    public function __construct(private readonly AuditRegistry $registry) {}

    public function id(): string
    {
        return 'og.image-alt-missing';
    }

    public function check(RenderedPage $page): array
    {
        $dom = $page->dom();
        if ($dom === null || (Html::meta($dom, 'property', 'og:image') ?? '') === '' || (Html::meta($dom, 'property', 'og:image:alt') ?? '') !== '') {
            return [];
        }

        return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Notice,
            'The share image has no description (og:image:alt); add one so it is described where the page is shared.')];
    }
}
