<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Ai\TokenUsage;
use Preflow\I18n\Machine\AiTranslator;
use Preflow\I18n\Machine\Estimator;
use Preflow\I18n\Machine\FileJobStore;
use Preflow\I18n\Machine\Job;
use Preflow\I18n\Machine\JobRunner;
use Preflow\I18n\Machine\JobStore;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Machine\WriteOutcome;
use Preflow\I18n\Machine\WriteOutcomeStatus;
use Preflow\I18n\Tests\Machine\Support\ArrayTextSource;

final class JobRunnerTest extends TestCase
{
    use MachineTestKit;

    private string $dir;
    private ArrayTextSource $source;
    private FakeAiClient $fake;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jobrunner_' . bin2hex(random_bytes(4));
        $this->source = new ArrayTextSource([
            '1' => ['title' => 'One', 'text' => 'First text'],
            '2' => ['title' => 'Two'],
            '3' => ['title' => 'Three'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @param list<mixed> $script */
    private function runner(array $script, ?JobStore $store = null): JobRunner
    {
        $this->fake = new FakeAiClient($script);
        $ai = $this->ai($this->fake);
        $sources = new TextSources();
        $sources->add('games', fn () => $this->source);

        return new JobRunner($sources, new AiTranslator($ai, $this->settings()), new Estimator($ai), $store ?? new FileJobStore($this->dir));
    }

    public function test_plan_estimates_without_writing(): void
    {
        $runner = $this->runner([self::echo()]);

        $job = $runner->plan('games', ['de', 'fr'], Scope::Needed, null, 'be brief');

        $this->assertSame('planned', $job->state);
        $this->assertSame(8, $job->estimate->units, '4 texts x 2 locales');
        $this->assertSame(['1', '2', '3'], $job->pending());
        $this->assertSame('pending', $job->items['1']['status']);
        $this->assertSame([], $this->source->writes);
        $this->assertSame([], $this->source->translations);
        $this->assertSame(0.0, $job->costUsd);
        $this->assertSame(1, preg_match('/\A[0-9a-f]{16}\z/', $job->id));
        $this->assertSame([], $this->fake->requests(), 'the runner\'s own client sent nothing');
    }

    public function test_step_translates_one_item_per_call_and_finishes(): void
    {
        $runner = $this->runner([self::echo()]);
        $job = $runner->plan('games', ['de'], Scope::Needed);

        $job = $runner->step($job->id);
        $this->assertSame('running', $job->state);
        $this->assertSame('done', $job->items['1']['status']);
        $this->assertSame(2, $job->items['1']['translated']);
        $this->assertSame('pending', $job->items['2']['status']);
        $this->assertSame('DE:First text', $this->source->translations['1']['de']['text']);

        $job = $runner->step($job->id);
        $job = $runner->step($job->id);
        $this->assertSame('done', $job->state);
        $this->assertSame(['1:de', '2:de', '3:de'], $this->source->writes);
        $this->assertSame('done', $runner->step($job->id)->state, 'a finished job stays finished');
        $this->assertCount(3, $this->source->writes);
    }

    public function test_step_can_take_several_items_and_costs_are_summed(): void
    {
        // Sonnet 5.5: 2.00 in / 10.00 out per million tokens; the fake model id is unpriced, so the configured model prices it.
        $priced = static fn (AiRequest $r) => FakeAiClient::json(
            array_map(static fn (array $i): array => ['n' => $i['n'], 'text' => 'DE:' . $i['text']], self::items($r)),
            new TokenUsage(1000, 500),
        );
        $runner = $this->runner([$priced]);
        $job = $runner->plan('games', ['de'], Scope::Needed);

        $job = $runner->step($job->id, 2);

        $this->assertSame('running', $job->state);
        $this->assertEqualsWithDelta(2 * 0.007, $job->costUsd, 1e-9, 'one request per item, 1000 in + 500 out each');

        $job = $runner->step($job->id, 5);
        $this->assertSame('done', $job->state);
        $this->assertEqualsWithDelta(3 * 0.007, $job->costUsd, 1e-9);
    }

    public function test_an_unpriced_request_makes_the_cost_unknown_and_it_is_kept(): void
    {
        $this->fake = new FakeAiClient([self::echo()]);
        $ai = $this->ai($this->fake, ['models' => ['claude' => 'unpriced-model']]);
        $sources = new TextSources();
        $sources->add('games', fn () => $this->source);
        $runner = new JobRunner($sources, new AiTranslator($ai, $this->settings()), new Estimator($ai), new FileJobStore($this->dir));
        $job = $runner->plan('games', ['de'], Scope::Needed);
        $this->assertFalse($job->costUnknown);

        $job = $runner->step($job->id);

        $this->assertTrue($job->costUnknown);
        $this->assertSame(0.0, $job->costUsd);
        $this->assertTrue((new FileJobStore($this->dir))->get($job->id)?->costUnknown, 'stored with the job');
    }

    public function test_conflict_reported_and_run_continues(): void
    {
        $this->source->outcomes['2'] = new WriteOutcome(WriteOutcomeStatus::Conflict, 'edited meanwhile', true);
        $runner = $this->runner([self::echo()]);
        $job = $runner->plan('games', ['de'], Scope::Needed);

        $job = $runner->step($job->id, 3);

        $this->assertSame('done', $job->state);
        $this->assertSame('conflict', $job->items['2']['status']);
        $this->assertSame('edited meanwhile', $job->items['2']['message']);
        $this->assertTrue($job->items['2']['draft_changes']);
        $this->assertSame('done', $job->items['3']['status']);
        $this->assertSame(0, $job->items['2']['translated']);
    }

    public function test_texts_left_alone_by_the_source_are_not_counted_and_named(): void
    {
        $this->source->outcomes['1'] = new WriteOutcome(WriteOutcomeStatus::Written, 'Changed since it was read, left alone: text.', false, ['text']);
        $runner = $this->runner([self::echo()]);
        $job = $runner->plan('games', ['de'], Scope::Needed);

        $job = $runner->step($job->id);

        $this->assertSame('done', $job->items['1']['status']);
        $this->assertSame(1, $job->items['1']['translated'], 'the title only');
        $this->assertSame('Changed since it was read, left alone: text.', $job->items['1']['message']);
    }

    public function test_failed_units_not_written_item_counts_failed(): void
    {
        // Answers only n=1 of every request: with one text per item that is fine, with two the second has no answer.
        $partial = static fn (AiRequest $r) => FakeAiClient::json([['n' => 1, 'text' => 'DE:' . self::items($r)[0]['text']]]);
        $runner = $this->runner([$partial]);
        $job = $runner->plan('games', ['de'], Scope::Needed, ['1']);

        $job = $runner->step($job->id);

        $this->assertSame('failed', $job->items['1']['status']);
        $this->assertSame(1, $job->items['1']['failed']);
        $this->assertSame(1, $job->items['1']['translated']);
        $this->assertSame('no answer', $job->items['1']['message']);
        $this->assertArrayHasKey('title', $this->source->translations['1']['de']);
        $this->assertArrayNotHasKey('text', $this->source->translations['1']['de']);
        $this->assertSame('done', $job->state);
    }

    public function test_unavailable_stops_job_and_keeps_done_items(): void
    {
        $runner = $this->runner([self::echo(), new AiUnavailable('no key')]);
        $store = new FileJobStore($this->dir);
        $job = $runner->plan('games', ['de'], Scope::Needed);
        $runner->step($job->id);

        try {
            $runner->step($job->id);
            $this->fail('AiUnavailable expected');
        } catch (AiUnavailable) {
        }

        $saved = $store->get($job->id);
        $this->assertSame('stopped', $saved->state);
        $this->assertSame('no key', $saved->message);
        $this->assertSame('done', $saved->items['1']['status']);
        $this->assertSame('pending', $saved->items['2']['status']);
    }

    public function test_stop_then_step_does_nothing(): void
    {
        $fake = new FakeAiClient([self::echo()]);
        $ai = $this->ai($fake);
        $sources = new TextSources();
        $sources->add('games', fn () => $this->source);
        $runner = new JobRunner($sources, new AiTranslator($ai, $this->settings()), new Estimator($ai), new FileJobStore($this->dir));
        $job = $runner->plan('games', ['de'], Scope::Needed);

        $this->assertSame('stopped', $runner->stop($job->id)->state);
        $job = $runner->step($job->id, 3);

        $this->assertSame('stopped', $job->state);
        $this->assertSame('pending', $job->items['1']['status']);
        $this->assertSame([], $fake->requests());
        $this->assertSame([], $this->source->writes);
    }

    public function test_item_with_no_units_skipped(): void
    {
        $this->source->items['2'] = [];
        $runner = $this->runner([self::echo()]);
        $job = $runner->plan('games', ['de'], Scope::Needed);

        $job = $runner->step($job->id, 3);

        $this->assertSame('skipped', $job->items['2']['status']);
        $this->assertSame('nothing to translate', $job->items['2']['message']);
        $this->assertSame('done', $job->state);
        $this->assertNotContains('2:de', $this->source->writes);
    }

    public function test_unknown_job_is_an_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runner([self::echo()])->step('nope');
    }

    public function test_start_runs_planned_and_stopped_jobs_and_clears_the_stop_message(): void
    {
        $runner = $this->runner([self::echo(), new AiUnavailable('no key'), self::echo()]);
        $store = new FileJobStore($this->dir);
        $job = $runner->plan('games', ['de'], Scope::Needed);

        $this->assertSame('running', $runner->start($job->id)->state);
        $this->assertSame('running', $store->get($job->id)->state, 'saved');
        $runner->step($job->id);
        try {
            $runner->step($job->id);
            $this->fail('AiUnavailable expected');
        } catch (AiUnavailable) {
        }
        $this->assertSame('no key', $store->get($job->id)->message);

        $job = $runner->start($job->id);

        $this->assertSame('running', $job->state);
        $this->assertNull($job->message);
        $this->assertSame('done', $job->items['1']['status'], 'items done before the stop stay done');
        $job = $runner->step($job->id, 5);
        $this->assertSame('done', $job->state);
        $this->assertSame('done', $job->items['2']['status']);
    }

    public function test_start_takes_up_a_job_left_running(): void
    {
        $runner = $this->runner([self::echo()]);
        $job = $runner->plan('games', ['de'], Scope::Needed);
        $runner->start($job->id);
        $runner->step($job->id); // then the page was closed: the job stays "running"

        $job = $runner->start($job->id);

        $this->assertSame('running', $job->state);
        $job = $runner->step($job->id, 5);
        $this->assertSame('done', $job->state);
        $this->assertSame(['done', 'done', 'done'], array_column($job->items, 'status'));
    }

    public function test_unfinished_lists_the_collections_open_jobs_newest_first(): void
    {
        $store = new FileJobStore($this->dir);
        $runner = $this->runner([self::echo()], $store);
        $done = $runner->plan('games', ['de'], Scope::Needed);
        $runner->step($done->id, 5);
        $planned = $runner->plan('games', ['de'], Scope::Needed);
        $stopped = $runner->plan('games', ['fr'], Scope::Needed);
        $runner->stop($stopped->id);
        $running = $runner->plan('games', ['it'], Scope::Needed);
        $runner->start($running->id);
        $other = new Job('0123456789abcdef', 'cards', ['de'], Scope::Needed, null, $planned->estimate);
        $store->save($other);
        foreach ([$done->id => 50, $planned->id => 10, $stopped->id => 20, $running->id => 30, $other->id => 40] as $id => $at) {
            touch($this->dir . '/' . $id . '.json', 1_700_000_000 + $at);
        }

        $ids = array_map(static fn (Job $j): string => $j->id, $runner->unfinished('games'));

        $this->assertSame([$running->id, $stopped->id, $planned->id], $ids, 'not done, this collection only, newest first');
        $this->assertCount(2, $runner->unfinished('games', 2));
    }

    public function test_start_leaves_a_done_job_unchanged(): void
    {
        $runner = $this->runner([self::echo()]);
        $job = $runner->plan('games', ['de'], Scope::Needed);
        $runner->step($job->id, 3);

        $this->assertSame('done', $runner->start($job->id)->state);
        $this->assertSame('done', (new FileJobStore($this->dir))->get($job->id)->state);
    }

    public function test_translator_error_fails_the_item_and_the_run_continues(): void
    {
        $runner = $this->runner([new \RuntimeException('Bad answer from the provider.'), self::echo()]);
        $job = $runner->plan('games', ['de'], Scope::Needed);

        $job = $runner->step($job->id, 3);

        $this->assertSame('failed', $job->items['1']['status']);
        $this->assertSame('Bad answer from the provider.', $job->items['1']['message']);
        $this->assertSame('done', $job->items['2']['status']);
        $this->assertSame('done', $job->items['3']['status']);
        $this->assertSame('done', $job->state);
    }

    public function test_write_error_fails_the_item_and_the_run_continues(): void
    {
        $this->source->throws['2'] = new \RuntimeException('Disk full.');
        $runner = $this->runner([self::echo()]);
        $job = $runner->plan('games', ['de'], Scope::Needed);

        $job = $runner->step($job->id);
        $job = $runner->step($job->id);

        $this->assertSame('failed', $job->items['2']['status']);
        $this->assertSame('Disk full.', $job->items['2']['message']);
        $this->assertSame('running', $job->state, 'one bad record does not block the run');
        $this->assertSame('done', $runner->step($job->id)->state);
    }

    public function test_a_stop_during_a_step_wins(): void
    {
        $runner = $this->runner([self::echo()]);
        $job = $runner->plan('games', ['de'], Scope::Needed);
        $runner->start($job->id);
        // Someone presses Stop while the first item is being written.
        $this->source->onWrite = static function () use ($runner, $job): void {
            $runner->stop($job->id);
        };

        $job = $runner->step($job->id, 3);

        $this->assertSame('stopped', $job->state);
        $this->assertSame('done', $job->items['1']['status'], 'the item in hand is kept');
        $this->assertSame('pending', $job->items['2']['status']);
        $this->assertSame('stopped', (new FileJobStore($this->dir))->get($job->id)->state);
        $this->assertSame(['1:de'], $this->source->writes);
    }
}
