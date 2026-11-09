<?php

declare(strict_types=1);

namespace App\Components\JobCard;

use Preflow\Components\Component;

/** One job as a card or a list row. Props: `job` (a JobResource array) and `view` (cards|list). */
final class JobCard extends Component
{
    protected string $tag = 'article';
    protected string $cssClass = 'job-card';
    protected bool $scopeCss = true;

    /** @var array<string, mixed> */
    public array $job = [];
    public string $view = 'cards';

    public function resolveState(): void
    {
        $this->job = (array) ($this->props['job'] ?? []);
        $this->view = ($this->props['view'] ?? 'cards') === 'list' ? 'list' : 'cards';
        // The wrapper's class is fixed, so the list variant is styled through this attribute.
        $this->setRootAttribute('data-view', $this->view);
    }
}
