<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiUnavailable;
use Preflow\Folio\Tests\Support\AiScaffold;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\I18n\Machine\FileJobStore;
use Preflow\I18n\Machine\FileRecordLease;
use Preflow\I18n\Machine\Job;
use Psr\Http\Message\ResponseInterface;

/**
 * The whole-type run screen: plan with an estimate, start, one record per step request with a
 * self-polling progress partial, stop and continue, and the results. With AI off nothing shows.
 */
final class AiRunTest extends TestCase
{
    use PublishingScaffold;
    use AiScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->aiConfig(true);
        $this->script = [self::echo()];
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'body' => ['en' => 'Body text'], '_version' => 1]);
        $this->working('post', 'p2', ['title' => ['en' => 'World'], 'slug' => ['en' => 'world'], '_version' => 1]);
    }

    protected function tearDown(): void
    {
        putenv(self::aiKeyEnv());
        $this->removeScaffold();
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $uri, array $body = [], bool $htmx = false): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($method === 'POST') {
            $request = $request->withParsedBody($body + ['_csrf_token' => $this->token]);
        }
        if ($htmx) {
            $request = $request->withHeader('HX-Request', 'true');
        }

        return ($this->session ?? $this->aiApp())->handle($request);
    }

    /** @param array<string, mixed> $body */
    private function estimate(string $type = 'post', array $body = []): ResponseInterface
    {
        return $this->request('POST', "/folio/{$type}/_ai/run", $body + ['step' => 'estimate', 'locales' => ['de'], 'scope' => 'needed']);
    }

    private static function jobId(ResponseInterface $res, string $type = 'post'): string
    {
        preg_match('#/folio/' . $type . '/_ai/jobs/([0-9a-f]{16})/start#', (string) $res->getBody(), $m);
        self::assertNotEmpty($m, 'the summary has a Start form');

        return $m[1];
    }

    private function job(string $id): Job
    {
        return (new FileJobStore($this->dir . '/storage/ai/jobs'))->get($id) ?? throw new \RuntimeException("no job {$id}");
    }

    private function step(string $id, string $type = 'post'): ResponseInterface
    {
        return $this->request('POST', "/folio/{$type}/_ai/jobs/{$id}/step", [], true);
    }

    private static function polls(ResponseInterface $res): bool
    {
        return str_contains((string) $res->getBody(), 'hx-trigger="load delay:300ms"');
    }

    public function test_estimate_shows_counts_and_cost(): void
    {
        $form = $this->request('GET', '/folio/post/_ai/run');
        $this->assertSame(200, $form->getStatusCode(), (string) $form->getBody());
        $html = (string) $form->getBody();
        $this->assertStringContainsString('name="locales[]" value="de"', $html);
        $this->assertStringNotContainsString('name="locales[]" value="en"', $html, 'the default language is the source');
        $this->assertStringContainsString('name="scope" value="needed"', $html);
        $this->assertStringContainsString('name="note"', $html);
        $this->assertStringContainsString('name="ids[]" value="p1"', (string) $this->request('GET', '/folio/post/_ai/run?ids[]=p1')->getBody());
        $this->assertStringContainsString('name="ids[]" value="p2"', (string) $this->request('POST', '/folio/post/_ai/run', ['ids' => ['p2']])->getBody(), 'the bulk bar posts the ticked ids');
        $before = $this->stored('post', 'p1')->toArray();

        $res = $this->estimate();

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertMatchesRegularExpression('/2 records, 5 texts, \d+ requests?, about (&lt; )?\$\d+\.\d\d/', (string) $res->getBody());
        $this->assertStringNotContainsString('name="confirm_live"', (string) $res->getBody(), 'posts are written as working copies');
        $job = $this->job(self::jobId($res));
        $this->assertSame('planned', $job->state);
        $this->assertSame(['de'], $job->locales);
        $this->assertCount(0, $this->fake->requests(), 'an estimate sends nothing');
        $this->assertSame($before, $this->stored('post', 'p1')->toArray());

        $one = $this->estimate('post', ['ids' => ['p1'], 'note' => 'Be brief']);
        $this->assertMatchesRegularExpression('/1 record, 3 texts, 1 request, about/', (string) $one->getBody());
        $this->assertSame('Be brief', $this->job(self::jobId($one))->note);

        $this->assertSame(422, $this->estimate('post', ['locales' => []])->getStatusCode(), 'a language is needed');
        $this->assertSame(422, $this->estimate('post', ['locales' => ['en']])->getStatusCode());
        $this->assertSame(422, $this->estimate('post', ['scope' => 'some'])->getStatusCode());
    }

    public function test_live_type_requires_confirmation(): void
    {
        $this->writeModel('faq', ['label' => 'FAQ', 'fields' => ['question' => ['type' => 'string', 'translatable' => true]]]);
        $this->working('faq', 'f1', ['question' => ['en' => 'Why?']]);

        $res = $this->estimate('faq');

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('name="confirm_live" value="1"', $html);
        $this->assertMatchesRegularExpression('#<button[^>]*data-folio-ai-start[^>]*disabled#', $html, 'Start waits for the box');
        $id = self::jobId($res, 'faq');

        $this->step($id, 'faq');
        $this->assertSame('planned', $this->job($id)->state, 'a step never runs a job that was not started');
        $refused = $this->request('POST', "/folio/faq/_ai/jobs/{$id}/start", [], true);
        $this->assertSame(422, $refused->getStatusCode());
        $this->assertSame('planned', $this->job($id)->state);
        $this->assertCount(0, $this->fake->requests());

        $started = $this->request('POST', "/folio/faq/_ai/jobs/{$id}/start", ['confirm_live' => '1'], true);
        $this->assertSame(200, $started->getStatusCode(), (string) $started->getBody());
        $this->assertSame('queued', $this->job($id)->state);
        $this->step($id, 'faq');
        $this->assertSame('DE:Why?', $this->stored('faq', 'f1')->toArray()['question']['de']);
    }

    public function test_step_advances_one_record_and_progress_polls_until_done(): void
    {
        // p2 is published and then changed: the AI texts land in a working copy that has other changes too.
        $this->publisher()->publish($this->stored('post', 'p2'), null);
        $this->working('post', 'p2', ['title' => ['en' => 'World!'], 'slug' => ['en' => 'world']]);
        $id = self::jobId($this->estimate());

        $start = $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true);
        $this->assertSame(200, $start->getStatusCode(), (string) $start->getBody());
        $this->assertStringContainsString('hx-post="/folio/post/_ai/jobs/' . $id . '/step"', (string) $start->getBody());
        $this->assertTrue(self::polls($start));
        $this->assertStringNotContainsString('<html', (string) $start->getBody(), 'htmx gets the partial');
        $this->assertSame('queued', $this->job($id)->state);
        $this->assertArrayNotHasKey('de', $this->stored('post', 'p1')->toArray()['title']);

        $first = $this->step($id);
        $this->assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $this->assertTrue(self::polls($first), 'still running');
        $this->assertStringContainsString('1 / 2', (string) $first->getBody());
        $this->assertSame(['done', 'pending'], array_column($this->job($id)->items, 'status'));
        $this->assertSame('DE:Hello', $this->stored('post', 'p1')->toArray()['title']['de']);

        $last = $this->step($id);
        $html = (string) $last->getBody();
        $this->assertFalse(self::polls($last), 'a finished job stops polling');
        $this->assertSame('done', $this->job($id)->state);
        $this->assertStringContainsString('2 / 2', $html);
        $this->assertStringContainsString('Review: 2 records with unchecked texts', $html);
        $this->assertStringContainsString('href="/folio/post?unchecked=1"', $html);
        $this->assertMatchesRegularExpression('#data-folio-ai-draft-changes.*World!#s', $html, 'p2 is listed with the records that had other changes');
        $this->assertDoesNotMatchRegularExpression('#data-folio-ai-draft-changes.*Hello#s', $html);
        $this->assertSame('DE:World!', $this->stored('post', 'p2')->toArray()['title']['de']);
        $this->assertSame('World', $this->publisher()->snapshot('post', 'p2')?->toArray()['title']['en'], 'nothing is published');

        $page = $this->request('POST', "/folio/post/_ai/jobs/{$id}/step");
        $this->assertStringContainsString('<html', (string) $page->getBody(), 'without htmx the whole page');
    }

    public function test_start_queues_the_job(): void
    {
        $id = self::jobId($this->estimate());

        $start = $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true);

        $this->assertSame(200, $start->getStatusCode());
        $job = $this->job($id);
        $this->assertSame('queued', $job->state);
        $this->assertNotNull($job->queuedAt);
        $this->assertCount(0, $this->fake->requests(), 'starting translates nothing');
        $this->assertStringContainsString('Waiting', (string) $this->request('GET', '/folio/post/_ai/run')->getBody());
    }

    public function test_step_skips_a_record_the_worker_holds(): void
    {
        $id = self::jobId($this->estimate());
        $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true);
        $this->assertTrue((new FileRecordLease($this->dir . '/storage/ai/leases'))->claim('post', 'p1'));

        $res = $this->step($id);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame(['pending', 'done'], array_column($this->job($id)->items, 'status'), 'p1 is left to the worker');
        $this->assertArrayNotHasKey('de', $this->stored('post', 'p1')->toArray()['title']);
        $this->assertTrue(self::polls($res), 'the page keeps polling');
    }

    public function test_status_page_without_worker_shows_the_cron_line(): void
    {
        $id = self::jobId($this->estimate());

        $html = (string) $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true)->getBody();

        $this->assertStringContainsString('Keep this page open', $html);
        $this->assertStringContainsString('translate:work', $html);
    }

    public function test_status_page_with_recent_worker_says_page_can_be_left(): void
    {
        $this->write('storage/ai/worker.json', (string) json_encode(['last_run' => (new \DateTimeImmutable('-1 minute'))->format(\DATE_ATOM)]));
        $id = self::jobId($this->estimate());

        $html = (string) $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true)->getBody();

        $this->assertStringContainsString('You can leave this page', $html);
        $this->assertStringNotContainsString('translate:work', $html);
        $this->assertTrue(self::polls($this->step($id)), 'the open page still drives steps');
    }

    public function test_an_unpriced_run_shows_its_cost_as_unknown(): void
    {
        $this->aiModel = 'unpriced-model';
        $id = self::jobId($this->estimate());
        $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true);

        $html = (string) $this->step($id)->getBody();

        $this->assertTrue($this->job($id)->costUnknown);
        $this->assertStringContainsString('cost so far —', $html);
        $this->assertStringNotContainsString('$0.00', $html);
    }

    public function test_progress_offers_continue_when_a_step_does_not_come_back(): void
    {
        $id = self::jobId($this->estimate());

        $html = (string) $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true)->getBody();

        $this->assertMatchesRegularExpression("#data-folio-ai-stalled hidden>\\s*<p>The last step didn't finish#", $html, 'admin.js shows it on a failed step');
        $this->assertMatchesRegularExpression('#data-folio-ai-stalled hidden>.*hx-post="/folio/post/_ai/jobs/' . $id . '/start".*Continue#s', $html);
    }

    public function test_a_run_left_running_is_listed_on_the_run_screen_and_continues(): void
    {
        $id = self::jobId($this->estimate());
        $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true);
        $this->step($id); // then the page was closed

        $screen = (string) $this->request('GET', '/folio/post/_ai/run')->getBody();

        $this->assertMatchesRegularExpression('#data-folio-ai-unfinished.*action="/folio/post/_ai/jobs/' . $id . '/start".*Waiting.*German · 1 / 2 records.*Continue#s', $screen);
        $start = $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true);
        $this->assertTrue(self::polls($start), 'Continue takes the running job up');
        $this->step($id);
        $this->assertSame('done', $this->job($id)->state);
        $this->assertStringNotContainsString('data-folio-ai-unfinished', (string) $this->request('GET', '/folio/post/_ai/run')->getBody(), 'a finished run is not offered');
    }

    public function test_a_step_survives_a_closed_tab_and_a_short_time_limit(): void
    {
        $id = self::jobId($this->estimate());
        $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true);
        ignore_user_abort(false);
        $limit = ini_get('max_execution_time');
        ini_set('max_execution_time', '30');
        try {
            $this->step($id);

            $this->assertSame(1, ignore_user_abort());
            $this->assertSame('300', ini_get('max_execution_time'));
        } finally {
            set_time_limit((int) $limit);
            ignore_user_abort(false);
        }
    }

    public function test_stop_keeps_done_records(): void
    {
        $id = self::jobId($this->estimate());
        $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true);
        $this->step($id);

        $stop = $this->request('POST', "/folio/post/_ai/jobs/{$id}/stop", [], true);

        $this->assertSame(200, $stop->getStatusCode(), (string) $stop->getBody());
        $this->assertFalse(self::polls($stop));
        $this->assertStringContainsString('action="/folio/post/_ai/jobs/' . $id . '/start"', (string) $stop->getBody(), 'it can be continued');
        $job = $this->job($id);
        $this->assertSame('stopped', $job->state);
        $this->assertSame(['done', 'pending'], array_column($job->items, 'status'));
        $this->assertSame('DE:Hello', $this->stored('post', 'p1')->toArray()['title']['de']);

        $this->assertFalse(self::polls($this->step($id)));
        $this->assertSame('pending', $this->job($id)->items['p2']['status'], 'a stopped job does not step');

        $this->assertTrue(self::polls($this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true)), 'Continue');
        $this->step($id);
        $this->assertSame('done', $this->job($id)->state);
    }

    public function test_unavailable_shows_message_and_stops(): void
    {
        $id = self::jobId($this->estimate());
        $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true);
        $this->script = [new AiUnavailable('The quota is used up.')];

        $res = $this->step($id);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertStringContainsString('The quota is used up.', (string) $res->getBody());
        $this->assertFalse(self::polls($res));
        $job = $this->job($id);
        $this->assertSame('stopped', $job->state);
        $this->assertSame(['pending', 'pending'], array_column($job->items, 'status'));
    }

    public function test_hidden_when_ai_off(): void
    {
        $on = (string) $this->request('GET', '/folio/post')->getBody();
        $this->assertStringContainsString('href="/folio/post/_ai/run"', $on, 'Translate all…');
        $this->assertStringContainsString('formaction="/folio/post/_ai/run"', $on, 'the bulk bar');
        $id = self::jobId($this->estimate());

        $this->aiConfig(false);

        $off = (string) $this->get('/folio/post')->getBody();
        $this->assertStringNotContainsString('_ai/run', $off);
        $this->assertSame(404, $this->get('/folio/post/_ai/run')->getStatusCode());
        $this->assertSame(404, $this->post('/folio/post/_ai/run', ['step' => 'estimate', 'locales' => ['de']])->getStatusCode());
        foreach (['start', 'step', 'stop'] as $action) {
            $this->assertSame(404, $this->post("/folio/post/_ai/jobs/{$id}/{$action}")->getStatusCode(), $action);
        }
    }

    public function test_job_belongs_to_its_type(): void
    {
        $id = self::jobId($this->estimate());

        $this->assertSame(404, $this->request('POST', "/folio/note/_ai/jobs/{$id}/start", [], true)->getStatusCode());
        $this->assertSame(404, $this->step($id, 'note')->getStatusCode());
        $this->assertSame(404, $this->step('ffffffffffffffff')->getStatusCode());
        $this->assertSame('planned', $this->job($id)->state);
    }

    #[RunInSeparateProcess]
    public function test_needs_ai_translate_and_edit(): void
    {
        $this->staff('editor');
        $this->assertStringNotContainsString('_ai/run', (string) $this->request('GET', '/folio/post')->getBody());
        $this->assertSame(403, $this->request('GET', '/folio/post/_ai/run')->getStatusCode());
        $this->assertSame(403, $this->estimate()->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_translator_runs_with_csrf(): void
    {
        $this->staff('translator');
        $this->assertStringContainsString('href="/folio/post/_ai/run"', (string) $this->request('GET', '/folio/post')->getBody());
        $res = $this->estimate();
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $id = self::jobId($res);

        $start = (new Psr17Factory())->createServerRequest('POST', "/folio/post/_ai/jobs/{$id}/start")->withHeader('HX-Request', 'true');
        $this->assertSame(403, $this->session->handle($start)->getStatusCode(), 'no token, no start');

        $started = $this->request('POST', "/folio/post/_ai/jobs/{$id}/start", [], true);
        $this->assertSame(200, $started->getStatusCode());
        $this->assertMatchesRegularExpression('#name="_csrf_token" value="' . preg_quote($this->token, '#') . '"#', (string) $started->getBody(), 'the poll sends the token');
        $this->assertSame(200, $this->step($id)->getStatusCode());
        $this->assertSame('done', $this->job($id)->items['p1']['status']);
    }
}
