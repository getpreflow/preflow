<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;

/**
 * `hreflang.x-default-missing` (notice): on a site with several languages, a page
 * that names its language versions (hreflang) but no x-default for visitors
 * whose language isn't among them. A page in one language only is fine.
 *
 * @since 0.54
 */
final class HreflangXDefaultMissing implements PageCheck
{
    public function __construct(
        private readonly AuditRegistry $registry,
        private readonly bool $multilingual,
    ) {}

    public function id(): string
    {
        return 'hreflang.x-default-missing';
    }

    public function check(RenderedPage $page): array
    {
        if (!$this->multilingual) {
            return [];
        }
        $alternates = Html::alternates($page);
        if ($alternates === [] || isset($alternates['x-default'])) {
            return [];
        }

        return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Notice,
            'The page names its language versions but no x-default; add <link rel="alternate" hreflang="x-default"> for the version other visitors should get.')];
    }
}
