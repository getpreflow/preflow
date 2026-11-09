<?php

declare(strict_types=1);

namespace App\Components\Pager;

use App\Jobs\JobFilters;
use Preflow\Components\Component;

/**
 * Previous and next links under the results. Props: `page`, `lastPage`, `query` (the filters as
 * JobFilters::toQuery() gives them), and `search`/`searchId`, the JobSearch component whose
 * search action the links run.
 *
 * Each link is a real address (it works without JavaScript and can be opened in a new tab) and
 * also posts the search action with its page, so with JavaScript only the results are swapped.
 */
final class Pager extends Component
{
    protected string $cssClass = 'pager';
    protected bool $scopeCss = true;

    public int $page = 1;
    public int $lastPage = 1;
    public ?int $prev = null;
    public ?int $next = null;
    public ?string $prevUrl = null;
    public ?string $nextUrl = null;
    public string $search = '';
    public string $searchId = '';

    public function resolveState(): void
    {
        $filters = JobFilters::fromArray((array) ($this->props['query'] ?? []));
        $this->page = $filters->page;
        $this->lastPage = max(1, (int) ($this->props['lastPage'] ?? 1));
        $this->search = (string) ($this->props['search'] ?? '');
        $this->searchId = (string) ($this->props['searchId'] ?? '');

        if ($this->page > 1) {
            // Past the last page, "previous" leads back to the last one that has jobs.
            $this->prev = min($this->page - 1, $this->lastPage);
            $this->prevUrl = $filters->url('/jobs', ['page' => $this->prev === 1 ? null : $this->prev]);
        }
        if ($this->page < $this->lastPage) {
            $this->next = $this->page + 1;
            $this->nextUrl = $filters->url('/jobs', ['page' => $this->next]);
        }
    }
}
