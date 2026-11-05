<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\RunCheck;
use Preflow\View\Audit\Severity;

/**
 * `description.duplicate` (notice): pages of the same locale with the same meta
 * description, each of them reported.
 *
 * @since 0.54
 */
final class DescriptionDuplicate implements RunCheck
{
    private DuplicateTexts $descriptions;

    public function __construct(private readonly AuditRegistry $registry)
    {
        $this->descriptions = new DuplicateTexts();
    }

    public function id(): string
    {
        return 'description.duplicate';
    }

    public function collect(RenderedPage $page): void
    {
        $dom = $page->dom();
        if ($dom !== null) {
            $this->descriptions->add($page->locale, Html::meta($dom, 'name', 'description') ?? '', $page->url);
        }
    }

    public function finish(): array
    {
        $findings = [];
        foreach ($this->descriptions->duplicates() as $dup) {
            $findings[] = Html::finding($this->registry, $this->id(), Severity::Notice,
                'The meta description is also the description of ' . DuplicateTexts::others($dup['others']) . '; describe each page in its own words.',
                $dup['url'], $dup['locale']);
        }

        return $findings;
    }

    public function state(): array
    {
        return $this->descriptions->state();
    }

    public function restore(array $state): void
    {
        $this->descriptions->restore($state);
    }
}
