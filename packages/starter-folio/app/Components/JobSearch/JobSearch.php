<?php

declare(strict_types=1);

namespace App\Components\JobSearch;

use App\Jobs\JobFilters;
use App\Jobs\JobResource;
use App\Jobs\JobSearch as Jobs;
use Preflow\Components\Component;
use Preflow\Htmx\HypermediaDriver;

/**
 * The search form on /jobs with its results. Props: the query string (q, place, type, remote,
 * view, page), so a link to /jobs?… renders the same list without JavaScript.
 *
 * With JavaScript, the form posts to the `search` action on every change and only the results
 * region is swapped: the search box is never replaced, so it keeps focus, caret and text.
 */
final class JobSearch extends Component
{
    protected string $tag = 'section';
    protected string $cssClass = 'job-search';
    protected bool $scopeCss = true;

    public JobFilters $filters;
    /** @var list<string> */
    public array $places = [];
    /** @var list<array<string, mixed>> */
    public array $jobs = [];
    public int $total = 0;
    public int $lastPage = 1;
    /** An action answers with the results region only; the page renders the whole form. */
    public bool $resultsOnly = false;

    public function __construct(
        private readonly Jobs $search,
        private readonly JobResource $resource,
        private readonly HypermediaDriver $hd,
    ) {}

    public function resolveState(): void
    {
        $this->places = $this->search->places();
        $this->find(JobFilters::fromArray($this->props));
    }

    public function actions(): array
    {
        return ['search'];
    }

    /**
     * $params is the form as the visitor left it. The page is not a form field: a change to the
     * form starts again at page 1, and a pager link carries its page in the signed props.
     *
     * @param array<string, mixed> $params
     */
    public function actionSearch(array $params = []): void
    {
        // The endpoint resolves the default state (resolveState, from the signed props) before it
        // runs the action, so a search runs twice here. Cheap at this size; not worth bypassing.
        $this->find(JobFilters::fromArray(['page' => $this->props['page'] ?? 1] + array_diff_key($params, ['page' => true])));
        $this->resultsOnly = true;
        // The address bar follows the search, so a reload or a shared link shows the same list.
        $this->hd->pushUrl($this->filters->url());
    }

    private function find(JobFilters $filters): void
    {
        $this->filters = $filters;
        $result = $this->search->search($filters);
        $companies = $this->search->companiesById(array_map(fn ($job): string => (string) $job->company, $result->items));
        $this->jobs = array_map(
            fn ($job): array => $this->resource->toArray($job, $companies[$job->company] ?? null),
            array_values($result->items),
        );
        $this->total = $result->total;
        $this->lastPage = $result->lastPage;
    }
}
