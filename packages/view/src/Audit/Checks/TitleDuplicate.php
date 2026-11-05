<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\RunCheck;
use Preflow\View\Audit\Severity;

/**
 * `title.duplicate` (warning): pages of the same locale with the same title, each
 * of them reported.
 *
 * @since 0.54
 */
final class TitleDuplicate implements RunCheck
{
    private DuplicateTexts $titles;

    public function __construct(private readonly AuditRegistry $registry)
    {
        $this->titles = new DuplicateTexts();
    }

    public function id(): string
    {
        return 'title.duplicate';
    }

    public function collect(RenderedPage $page): void
    {
        $dom = $page->dom();
        if ($dom !== null) {
            $this->titles->add($page->locale, Html::title($dom) ?? '', $page->url);
        }
    }

    public function finish(): array
    {
        $findings = [];
        foreach ($this->titles->duplicates() as $dup) {
            $findings[] = Html::finding($this->registry, $this->id(), Severity::Warning,
                "The title “{$dup['text']}” is also the title of " . DuplicateTexts::others($dup['others']) . '; give each page its own title.',
                $dup['url'], $dup['locale']);
        }

        return $findings;
    }

    public function state(): array
    {
        return $this->titles->state();
    }

    public function restore(array $state): void
    {
        $this->titles->restore($state);
    }
}
