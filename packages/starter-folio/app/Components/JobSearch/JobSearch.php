<?php

declare(strict_types=1);

namespace App\Components\JobSearch;

use App\Jobs\JobFilters;
use App\Jobs\JobResource;
use App\Jobs\JobSearch as Jobs;
use Preflow\Components\Component;
use Preflow\Components\State;
use Preflow\Core\Exceptions\HttpException;
use Preflow\Htmx\HypermediaDriver;

/**
 * The search form on /jobs with its results. Props: the query string (q, place, type, remote,
 * view, page), so a link to /jobs?… renders the same list without JavaScript.
 *
 * The filters are its state: mount() takes them from the props once, and every action starts
 * from the filters the page shows. With JavaScript, a change to the form runs `search`, a pager
 * link runs `page`, and only the results region comes back: the search box is never replaced,
 * so it keeps focus, caret and text.
 */
final class JobSearch extends Component
{
    protected string $tag = 'section';
    protected string $cssClass = 'job-search';
    protected bool $scopeCss = true;

    #[State]
    public JobFilters $filters;

    /** @var list<string> */
    public array $places = [];
    /** @var list<array<string, mixed>> */
    public array $jobs = [];
    public int $total = 0;
    public int $lastPage = 1;

    public function __construct(
        private readonly Jobs $search,
        private readonly JobResource $resource,
        private readonly HypermediaDriver $hd,
    ) {}

    public function mount(): void
    {
        $this->filters = JobFilters::fromArray($this->props);
    }

    /** Before every render: the list for the filters, whether they came from the props or an action. */
    public function prepare(): void
    {
        $this->places = $this->search->places();
        $result = $this->search->search($this->filters);
        $companies = $this->search->companiesById(array_map(fn ($job): string => (string) $job->company, $result->items));
        $this->jobs = array_map(
            fn ($job): array => $this->resource->toArray($job, $companies[$job->company] ?? null),
            array_values($result->items),
        );
        $this->total = $result->total;
        $this->lastPage = $result->lastPage;
    }

    public function actions(): array
    {
        return ['search', 'page'];
    }

    /**
     * The form changed. $params is the form as the visitor left it, read like the address bar:
     * anything odd becomes the default. A new search starts at page 1.
     *
     * @param array<string, mixed> $params
     */
    public function actionSearch(array $params): void
    {
        $this->filters = JobFilters::fromArray($params)->with(['page' => 1]);
        // The address bar follows the search, so a reload or a shared link shows the same list.
        $this->hd->pushUrl($this->filters->url());
    }

    /**
     * A pager link: only the page changes, the filters are the ones the page shows. The page is
     * input: a whole number from 1, or the request is refused. Past the last page (the list got
     * shorter since the page was rendered) it shows the last one.
     *
     * @param array<string, mixed> $params
     */
    public function actionPage(array $params): void
    {
        $page = filter_var($params['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) {
            throw new HttpException(400, 'Not a page number.');
        }
        $this->filters = $this->filters->with(['page' => min($page, $this->search->lastPage($this->filters))]);
        $this->hd->pushUrl($this->filters->url());
    }
}
