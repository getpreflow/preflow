<?php

declare(strict_types=1);

namespace App\Components\JobResults;

use Preflow\Components\Component;

/** A page of search results. Props: `jobs` (JobResource arrays), `total` (all matches), `view`. */
final class JobResults extends Component
{
    protected string $cssClass = 'job-results';
    protected bool $scopeCss = true;

    /** @var list<array<string, mixed>> */
    public array $jobs = [];
    public int $total = 0;
    public string $view = 'cards';

    public function resolveState(): void
    {
        $this->jobs = array_values((array) ($this->props['jobs'] ?? []));
        $this->total = (int) ($this->props['total'] ?? 0);
        $this->view = ($this->props['view'] ?? 'cards') === 'list' ? 'list' : 'cards';
    }
}
