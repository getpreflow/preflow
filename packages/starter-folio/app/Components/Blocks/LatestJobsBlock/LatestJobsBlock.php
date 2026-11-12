<?php

declare(strict_types=1);

namespace App\Components\Blocks\LatestJobsBlock;

use App\Jobs\JobResource;
use App\Jobs\JobSearch;
use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;

/**
 * The newest published jobs as cards, with a link to all of them. Its data comes from the app
 * (JobSearch), not from the page: staff only choose where it goes and how many it shows.
 */
#[Block(key: 'latest_jobs', label: 'Latest jobs', group: 'Jobs', description: 'The newest published jobs')]
final class LatestJobsBlock extends BlockComponent
{
    protected string $tag = 'section';
    protected string $cssClass = 'latest-jobs';
    protected bool $scopeCss = true;

    #[BlockField(type: 'string')]
    public string $title = 'Latest jobs';

    #[BlockField(type: 'number', help: 'How many jobs to show (1 to 24). Empty shows 6.')]
    public int $count = 6;

    /** @var list<array<string, mixed>> */
    public array $jobs = [];

    public function __construct(
        private readonly JobSearch $search,
        private readonly JobResource $resource,
    ) {}

    public function resolveState(): void
    {
        $count = $this->count > 0 ? min(24, $this->count) : 6;
        $latest = $this->search->latest($count);
        // One query for all the companies, not one per card.
        $companies = $this->search->companiesById(array_map(fn ($job): string => (string) $job->company, $latest));

        $this->jobs = array_map(
            fn ($job): array => $this->resource->toArray($job, $companies[$job->company] ?? null),
            $latest,
        );
    }
}
