<?php

declare(strict_types=1);

namespace App\Components\LatestJobs;

use App\Jobs\JobResource;
use App\Jobs\JobSearch;
use Preflow\Components\Component;

/** The newest published jobs as cards. Prop: `count` (default 6). */
final class LatestJobs extends Component
{
    protected string $tag = 'section';
    protected string $cssClass = 'latest-jobs';
    protected bool $scopeCss = true;

    /** @var list<array<string, mixed>> */
    public array $jobs = [];

    public function __construct(
        private readonly JobSearch $search,
        private readonly JobResource $resource,
    ) {}

    public function resolveState(): void
    {
        $count = max(1, min(24, (int) ($this->props['count'] ?? 6)));
        $latest = $this->search->latest($count);
        // One query for all the companies, not one per card.
        $companies = $this->search->companiesById(array_map(fn ($job): string => (string) $job->company, $latest));

        $this->jobs = array_map(
            fn ($job): array => $this->resource->toArray($job, $companies[$job->company] ?? null),
            $latest,
        );
    }
}
