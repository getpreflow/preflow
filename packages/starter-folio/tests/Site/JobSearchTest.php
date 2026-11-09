<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Models\Job;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Publishing\PublishedModels;
use Preflow\Testing\TestResponse;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\StarterApp;

/** The /jobs page: filters in the address, the search action behind the form, view toggle and paging. */
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
     * The published jobs, newest first (posted on, then published at), that pass $keep: what the
     * list should show, worked out without the search code.
     *
     * @param callable(Job): bool $keep
     * @return list<string> slugs
     */
    private function expected(callable $keep): array
    {
        $jobs = array_filter(
            $this->container()->get(PublishedModels::class)->query(Job::class)->get()->items(),
            $keep,
        );
        usort($jobs, static fn (Job $a, Job $b): int => [(string) $b->postedOn, (string) $b->_published_at] <=> [(string) $a->postedOn, (string) $a->_published_at]);

        return array_map(static fn (Job $job): string => (string) $job->slug, $jobs);
    }

    /** @return list<string> the job slugs linked in $html, in order */
    private function listed(string $html): array
    {
        preg_match_all('#href="/jobs/([a-z0-9-]+)"#', $html, $matches);

        return $matches[1];
    }

    /** @return array{0: string, 1: string} the form's hx-post URL and the id of its results region */
    private function form(string $html): array
    {
        $this->assertSame(1, preg_match('#<form[^>]*hx-post="([^"]+)"#', $html, $post), 'the form posts the search action');
        $this->assertSame(1, preg_match('#<form[^>]*hx-target="\#([^"]+)"#', $html, $target));

        return [html_entity_decode($post[1]), $target[1]];
    }

    /**
     * What htmx sends when the form changes: the form's values to its hx-post URL, with the
     * results region as HX-Target.
     *
     * @param array<string, mixed> $body
     */
    private function search(array $body): ResponseInterface
    {
        [$url, $results] = $this->form($this->get('/jobs')->body());

        return $this->send('POST', $url, $body, ['HX-Request' => 'true', 'HX-Target' => $results]);
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

    public function test_form_targets_results_and_syncs(): void
    {
        $html = $this->get('/jobs')->body();

        $this->assertMatchesRegularExpression('#<form[^>]*method="get"[^>]*action="/jobs"#', $html);
        $this->assertMatchesRegularExpression('#<form[^>]*hx-sync="this:replace"#', $html);
        // Enter in the search box submits the form: htmx must take that over, not the browser.
        $this->assertMatchesRegularExpression('#<form[^>]*hx-trigger="[^"]*\bsubmit\b#', $html);
        $this->assertMatchesRegularExpression('#<form[^>]*hx-swap="innerHTML"#', $html);
        [, $results] = $this->form($html);
        $this->assertStringEndsWith('-results', $results);
        $this->assertStringContainsString('id="' . $results . '"', $html);
        $this->assertMatchesRegularExpression('#<noscript>\s*<button[^>]*type="submit"#', $html);
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

    public function test_pager_links_run_the_search_action_with_their_page(): void
    {
        $html = $this->get('/jobs?remote=full')->body();
        $this->assertSame(1, preg_match('#href="/jobs\?remote=full&amp;page=2" rel="next"[^>]*hx-post="([^"]+)"#', $html, $next));
        [, $results] = $this->form($html);

        // htmx sends the enclosing form's values with the link's request.
        $response = $this->send('POST', html_entity_decode($next[1]), ['remote' => 'full'], ['HX-Request' => 'true', 'HX-Target' => $results]);

        $all = $this->expected(fn (Job $job): bool => $job->remote === 'full');
        $this->assertSame(array_slice($all, 12, 12), $this->listed((string) $response->getBody()));
        $this->assertSame('/jobs?remote=full&page=2', $response->getHeaderLine('HX-Push-Url'));
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
