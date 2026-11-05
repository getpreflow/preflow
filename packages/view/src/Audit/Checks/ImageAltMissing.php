<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;

/**
 * `img.alt-missing` (warning): images without an alt attribute, once per page with
 * their count. An empty alt (a decorative image) is fine.
 *
 * @since 0.54
 */
final class ImageAltMissing implements PageCheck
{
    public function __construct(private readonly AuditRegistry $registry) {}

    public function id(): string
    {
        return 'img.alt-missing';
    }

    public function check(RenderedPage $page): array
    {
        $dom = $page->dom();
        if ($dom === null) {
            return [];
        }

        $missing = [];
        foreach ($dom->getElementsByTagName('img') as $img) {
            if (!$img->hasAttribute('alt')) {
                $missing[] = trim($img->getAttribute('src'));
            }
        }
        if ($missing === []) {
            return [];
        }

        $count = count($missing);
        $example = $missing[0] !== '' && !str_starts_with($missing[0], 'data:') ? " (first: {$missing[0]})" : '';
        $what = $count === 1 ? '1 image has' : "{$count} images have";

        return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Warning,
            "{$what} no alt text{$example}; describe each image, or give a decorative one alt=\"\".")];
    }
}
