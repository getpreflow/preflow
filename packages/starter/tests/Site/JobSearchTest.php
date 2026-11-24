<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Components\JobSearch\JobSearch as SearchComponent;
use App\Models\Job;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\Hypermedia\DrivesComponentActions;
use Preflow\Data\DataManager;
use Preflow\Testing\TestResponse;
use Preflow\Testing\WebApplication;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\StarterApp;

/** The /jobs page: filters in the address, the search and page actions, view toggle and paging. */
final class JobSearchTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    /**
     * The published jobs, newest first, that pass $keep: what the list should show, worked out
     * without the search code.
     *
     * @param callable(Job): bool $keep
     * @return list<string> slugs
     */
    private function expected(callable $keep): array
    {
        $jobs = array_filter(
            $this->container()->get(DataManager::class)->query(Job::class)->where('status', 'published')->get()->items(),
            $keep,
        );
        usort($jobs, static fn (Job $a, Job $b): int => strcmp((string) $b->publishedAt, (string) $a->publishedAt));

        return array_map(static fn (Job $job): string => (string) $job->slug, $jobs);
    }

    /** @return list<string> the job slugs linked in $html, in order */
    private function listed(string $html): array
    {
        preg_match_all('#href="/jobs/([a-z0-9-]+)"#', $html, $matches);

        return $matches[1];
    }

    /** The id of the JobSearch component on /jobs (it has no key, so there is one). */
    private function searchId(): string
    {
        return $this->container()->make(SearchComponent::class)->getComponentId();
    }

    /**
     * Runs an action of the job search on $page (the HTML of /jobs) the way the page does: with
     * the state the page carries, and the results region as the part that comes back.
     *
     * @param array<string, mixed> $params the form's fields and the call's values
     */
    private function act(string $page, string $action, array $params): ResponseInterface
    {
        return WebApplication::componentAction(
            $this->app(),
            $page,
            SearchComponent::class,
            $action,
            $params + [DrivesComponentActions::REGION_FIELD => 'results'],
        );
    }

    /**
     * What happens when the form on /jobs changes: the search action with the form's fields.
     *
     * @param array<string, mixed> $form
     */
    private function search(array $form): ResponseInterface
    {
        return $this->act($this->get('/jobs')->body(), 'search', $form);
    }

    public function test_url_filters_render_server_side(): void
    {
        $response = $this->get('/jobs?remote=full&type=contract');

        $response->assertOk();
        $expected = $this->expected(fn (Job $job): bool => $job->remote === 'full' && $job->employmentType === 'contract');
        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $this->listed($response->body()));
        $this->assertMatchesRegularExpression('#<input[^>]*name="remote"[^>]*value="full"[^>]*checked#', $response->body());
        $this->assertMatchesRegularExpression('#<input[^>]*name="type"[^>]*value="contract"[^>]*checked#', $response->body());
        $this->assertDoesNotMatchRegularExpression('#<input[^>]*name="remote"[^>]*value="hybrid"[^>]*checked#', $response->body());
        $response->assertSee(count($expected) . ' jobs');
    }

    public function test_search_box_and_filters_combine_without_js(): void
    {
        $response = $this->get('/jobs?q=engineer&place=Berlin');

        $expected = $this->expected(fn (Job $job): bool => $job->place === 'Berlin'
            && stripos($job->title . ' ' . $job->description, 'engineer') !== false);
        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $this->listed($response->body()));
        $this->assertMatchesRegularExpression('#<input[^>]*name="q"[^>]*value="engineer"#', $response->body());
    }

    public function test_search_action_filters_and_pushes_url(): void
    {
        $raw = $this->search(['q' => 'Engineer', 'remote' => 'hybrid']);
        $response = new TestResponse($raw);

        $response->assertOk();
        $expected = $this->expected(fn (Job $job): bool => $job->remote === 'hybrid'
            && stripos($job->title . ' ' . $job->description, 'engineer') !== false);
        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $this->listed($response->body()));
        $this->assertSame('/jobs?q=Engineer&remote=hybrid', $raw->getHeaderLine('HX-Push-Url'));
        // Only the results come back: the search box is not replaced while someone types in it.
        $response->assertNotSee('name="q"');
        $response->assertNotSee('<form');
    }

    public function test_search_action_sets_no_cookie(): void
    {
        $page = $this->send('GET', '/jobs');
        $this->assertSame([], $page->getHeader('Set-Cookie'));

        $response = $this->search(['q' => 'developer']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('jobs', (string) $response->getBody());
        $this->assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function test_form_works_without_javascript(): void
    {
        $html = $this->get('/jobs')->body();

        $this->assertMatchesRegularExpression('#<form[^>]*method="get"[^>]*action="/jobs"#', $html);
        $this->assertMatchesRegularExpression('#<noscript>\s*<button[^>]*type="submit"#', $html);
        // The results are a region of the form: an action renders only that part.
        $this->assertMatchesRegularExpression('#<div id="' . preg_quote($this->searchId(), '#') . '-results" class="job-search__results">#', $html);
    }

    public function test_view_toggle_list(): void
    {
        $list = $this->get('/jobs?view=list')->body();
        $this->assertStringContainsString('class="job-list', $list);
        $this->assertMatchesRegularExpression('#<input[^>]*name="view"[^>]*value="list"[^>]*checked#', $list);
        $this->assertCount(12, $this->listed($list));

        $cards = $this->get('/jobs')->body();
        $this->assertStringNotContainsString('class="job-list', $cards);
        $this->assertStringContainsString('class="job-grid', $cards);

        $action = $this->search(['view' => 'list']);
        (new TestResponse($action))->assertSee('class="job-list');
        $this->assertSame('/jobs?view=list', $action->getHeaderLine('HX-Push-Url'));
    }

    public function test_paging(): void
    {
        $all = $this->expected(fn (): bool => true);
        $this->assertCount(34, $all);

        $page2 = $this->get('/jobs?page=2');

        $page2->assertOk();
        $this->assertSame(array_slice($all, 12, 12), $this->listed($page2->body()));
        $page2->assertSee('href="/jobs" rel="prev"');
        $page2->assertSee('href="/jobs?page=3" rel="next"');
        $page2->assertSee('34 jobs');

        $page3 = $this->get('/jobs?page=3')->body();
        $this->assertSame(array_slice($all, 24), $this->listed($page3));
        $this->assertStringNotContainsString('rel="next"', $page3);
        $this->assertStringNotContainsString('rel="prev"', $this->get('/jobs')->body());
    }

    public function test_pager_links_run_the_page_action(): void
    {
        $page = $this->get('/jobs?remote=full')->body();
        $this->assertStringContainsString('href="/jobs?remote=full&amp;page=2" rel="next"', $page);

        // The link sends its page; the filters are the state the page carries.
        $response = $this->act($page, 'page', ['page' => 2]);

        $all = $this->expected(fn (Job $job): bool => $job->remote === 'full');
        $this->assertSame(array_slice($all, 12, 12), $this->listed((string) $response->getBody()));
        $this->assertSame('/jobs?remote=full&page=2', $response->getHeaderLine('HX-Push-Url'));
    }

    public function test_pager_keeps_the_filters(): void
    {
        // A visitor opens /jobs, picks "full" remote, then pages on: the page action never sees
        // the filters as input, they come back from the state the search left on the page.
        $page = $this->get('/jobs')->body();
        $searched = (string) $this->act($page, 'search', ['remote' => 'full'])->getBody();
        $state = WebApplication::stateFrom($searched, $this->searchId());
        $this->assertNotNull($state, 'the answer carries the new state');
        $page = str_replace((string) WebApplication::stateFrom($page, $this->searchId()), $state, $page);

        $response = $this->act($page, 'page', ['page' => 2]);

        $all = $this->expected(fn (Job $job): bool => $job->remote === 'full');
        $this->assertGreaterThan(12, count($all));
        $this->assertSame(array_slice($all, 12, 12), $this->listed((string) $response->getBody()));
        $this->assertSame('/jobs?remote=full&page=2', $response->getHeaderLine('HX-Push-Url'));
    }

    public function test_page_action_checks_its_page(): void
    {
        $page = $this->get('/jobs')->body();

        foreach (['two', 0, -1, ['2']] as $bad) {
            $this->assertSame(400, $this->act($page, 'page', ['page' => $bad])->getStatusCode(), var_export($bad, true));
        }
        // Past the last page (the list may have got shorter since the page was rendered): the last page.
        $last = $this->act($page, 'page', ['page' => 99]);
        $this->assertSame(array_slice($this->expected(fn (): bool => true), 24), $this->listed((string) $last->getBody()));
        $this->assertSame('/jobs?page=3', $last->getHeaderLine('HX-Push-Url'));
    }

    public function test_search_runs_once_per_action(): void
    {
        $page = $this->get('/jobs')->body();

        [, $pageReads] = $this->jobListReads(fn (Application $app): ResponseInterface => $app->handle(
            (new Psr17Factory())->createServerRequest('GET', '/jobs'),
        ));
        [$answer, $actionReads] = $this->jobListReads(fn (Application $app): ResponseInterface => WebApplication::componentAction(
            $app,
            $page,
            SearchComponent::class,
            'search',
            ['q' => 'engineer', DrivesComponentActions::REGION_FIELD => 'results'],
        ));

        $this->assertSame(200, $answer->getStatusCode());
        // The action reads the job list as often as the page does: the places once, the search
        // once. Nothing searches first with the filters the action is about to change.
        $this->assertGreaterThan(0, $pageReads);
        $this->assertSame($pageReads, $actionReads);
    }

    /**
     * Runs $request on a freshly booted app and counts how often it read a list of jobs.
     *
     * @param \Closure(Application): ResponseInterface $request
     * @return array{0: ResponseInterface, 1: int}
     */
    private function jobListReads(\Closure $request): array
    {
        $app = $this->app();
        [$response, $reads] = $app->container()->get(DataManager::class)->captureReads(fn (): ResponseInterface => $request($app));
        $listings = array_filter(
            $reads,
            static fn (array $read): bool => $read[0] === 'read' && str_contains((string) $read[2], 'job') && ($read[3] ?? null) === null,
        );

        return [$response, count($listings)];
    }

    public function test_tampered_state_is_refused(): void
    {
        $page = $this->get('/jobs?remote=full')->body();
        $state = (string) WebApplication::stateFrom($page, $this->searchId());
        $this->assertNotSame('', $state);
        $tampered = str_replace($state, substr($state, 0, -2) . (str_ends_with($state, 'AA') ? 'BB' : 'AA'), $page);

        $this->assertSame(403, $this->act($tampered, 'page', ['page' => 2])->getStatusCode());
    }

    public function test_pager_label_reads_page_1_of_3(): void
    {
        $this->assertMatchesRegularExpression('#<span class="pager__where">\s*Page 1 of 3\s*</span>#', $this->get('/jobs')->body());
    }

    public function test_jobs_page_stays_cacheable(): void
    {
        WebApplication::assertPageCacheable(WebApplication::boot($this->starterRoot), '/jobs');
        WebApplication::assertPageCacheable(WebApplication::boot($this->starterRoot), '/jobs?remote=full&page=2');
    }

    public function test_odd_values_do_not_fail(): void
    {
        $response = $this->get('/jobs?type=%3Cscript%3E&remote[]=x&page=999&q=' . urlencode('<script>alert(1)</script>'));

        $response->assertOk();
        $response->assertSee('No jobs match.');
        $response->assertSee('href="/jobs">Clear filters');
        $response->assertNotSee('<script>alert(1)');
        $this->assertSame([], $this->listed($response->body()));

        $this->get('/jobs?page=-3&q=' . str_repeat('x', 300))->assertOk();
        $this->assertSame(200, $this->search(['q' => ['x'], 'place' => ['y'], 'view' => 'grid'])->getStatusCode());
    }
}
