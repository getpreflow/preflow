<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\Freshness;

final class DependencyRecorderTest extends TestCase
{
    public function test_records_only_between_start_and_stop(): void
    {
        $recorder = new DependencyRecorder();
        $recorder->record('default:object');
        $this->assertFalse($recorder->recording());

        $recorder->start();
        $this->assertTrue($recorder->recording());
        $recorder->record('default:object', 'menu');
        $tags = $recorder->stop();
        $recorder->record('default:after');

        $this->assertSame([
            'default:object' => [Freshness::Fresh, null],
            'menu' => [Freshness::Fresh, null],
        ], $tags);
        $this->assertFalse($recorder->recording());
        $this->assertSame([], $recorder->stop());
    }

    public function test_a_record_also_records_any_record_of_its_table(): void
    {
        $recorder = new DependencyRecorder();
        $recorder->start();
        $recorder->record('default:object#5', 'default:object#6');

        $this->assertSame(['default:object#5', 'default:object#', 'default:object#6'], array_keys($recorder->stop()));
    }

    public function test_within_sets_the_freshness_and_the_strictest_wins(): void
    {
        $recorder = new DependencyRecorder();
        $recorder->start();
        $result = $recorder->within(Freshness::Timed, 43200, function () use ($recorder): string {
            $recorder->record('default:job');
            $recorder->record('default:company');
            $recorder->within(Freshness::NextVisit, null, fn () => $recorder->record('default:news'));
            $recorder->record('default:after_inner');

            return 'rendered';
        });
        $recorder->record('default:company'); // read again outside: Fresh wins
        $recorder->within(Freshness::Timed, 600, fn () => $recorder->record('default:job')); // the shorter time wins
        $recorder->within(Freshness::Timed, 99999, fn () => $recorder->record('default:job'));
        $recorder->within(Freshness::NextVisit, 5, fn () => $recorder->record('default:plain')); // seconds only for Timed

        $this->assertSame('rendered', $result);
        $this->assertSame([
            'default:job' => [Freshness::Timed, 600],
            'default:company' => [Freshness::Fresh, null],
            'default:news' => [Freshness::NextVisit, null],
            'default:after_inner' => [Freshness::Timed, 43200],
            'default:plain' => [Freshness::NextVisit, null],
        ], $recorder->stop());
    }

    public function test_within_restores_after_an_exception(): void
    {
        $recorder = new DependencyRecorder();
        $recorder->start();
        try {
            $recorder->within(Freshness::Timed, 60, static fn () => throw new \RuntimeException('x'));
        } catch (\RuntimeException) {
        }
        $recorder->record('default:object');

        $this->assertSame(['default:object' => [Freshness::Fresh, null]], $recorder->stop());
    }

    public function test_nested_recordings_count_for_the_outer_one_too(): void
    {
        $recorder = new DependencyRecorder();
        $recorder->start();
        $recorder->record('outer');
        $recorder->start();
        $recorder->record('inner');
        $inner = $recorder->stop();
        $outer = $recorder->stop();

        $this->assertSame(['inner'], array_keys($inner));
        $this->assertSame(['outer', 'inner'], array_keys($outer));
    }

    public function test_stopping_a_recording_closes_one_left_open_inside_it(): void
    {
        $recorder = new DependencyRecorder();
        $page = $recorder->start();
        $recorder->record('outer');
        $recorder->start(); // an inner render that threw before its stop()
        $recorder->record('inner');

        $this->assertSame(['outer', 'inner'], array_keys($recorder->stop($page)));
        $this->assertFalse($recorder->recording());
    }

    public function test_capture_closes_its_recording_when_the_render_throws(): void
    {
        $recorder = new DependencyRecorder();
        $page = $recorder->start();
        $recorder->record('outer');
        try {
            $recorder->capture(static function () use ($recorder): void {
                $recorder->record('part');
                throw new \RuntimeException('render failed');
            });
        } catch (\RuntimeException) {
        }
        [$result, $tags] = $recorder->capture(static function () use ($recorder): string {
            $recorder->record('second');

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(['second'], array_keys($tags));
        $this->assertSame(['outer', 'part', 'second'], array_keys($recorder->stop($page)));
    }

    public function test_enter_and_leave_nest_and_timed_needs_its_time(): void
    {
        $recorder = new DependencyRecorder();
        $recorder->start();
        $recorder->enter(Freshness::NextVisit);
        $recorder->enter(Freshness::Timed, 60);
        $recorder->record('inner');
        $recorder->leave();
        $recorder->record('middle');
        $recorder->leave();
        $recorder->leave(); // one too many: nothing to close
        $recorder->record('outer');

        $this->assertSame([
            'inner' => [Freshness::Timed, 60],
            'middle' => [Freshness::NextVisit, null],
            'outer' => [Freshness::Fresh, null],
        ], $recorder->stop());

        foreach ([null, 0, -5] as $seconds) {
            try {
                $recorder->enter(Freshness::Timed, $seconds);
                $this->fail('timed without a time');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $recorder->within(Freshness::Timed, null, fn () => null);
    }

    public function test_a_scope_is_closed_when_its_body_throws(): void
    {
        $recorder = new DependencyRecorder();
        $recorder->start();
        try {
            $recorder->within(Freshness::NextVisit, null, fn () => throw new \RuntimeException('render failed'));
        } catch (\RuntimeException) {
        }
        $recorder->record('after');

        $this->assertSame(['after' => [Freshness::Fresh, null]], $recorder->stop());
    }

    public function test_expiry_keeps_the_earliest_per_recording_and_reaches_the_outer_one(): void
    {
        $recorder = new DependencyRecorder();
        $recorder->expiresAt(100.0); // not recording: nothing
        $page = $recorder->start();
        $this->assertNull($recorder->expiry($page));
        $recorder->expiresAt(new \DateTimeImmutable('@500'));
        $part = $recorder->start();
        $recorder->expiresAt(300);
        $recorder->expiresAt(400.5);
        $this->assertSame(300.0, $recorder->expiry($part));
        $recorder->stop($part);
        $this->assertSame(300.0, $recorder->expiry($page), 'an inner render counts for the page around it');
        $this->assertSame(300.0, $recorder->expiry());
        $recorder->stop($page);
        $this->assertNull($recorder->expiry());

        $again = $recorder->start();
        $this->assertNull($recorder->expiry($again), 'a new recording starts without one');
        $recorder->stop($again);
    }

    public function test_outside_records_no_reads_no_expiry_and_no_freshness_of_the_scope_around(): void
    {
        $recorder = new DependencyRecorder();
        $page = $recorder->start();
        $recorder->within(Freshness::NextVisit, null, function () use ($recorder): void {
            $recorder->outside(function () use ($recorder): void {
                // A live part: what it reads and asks for is not the page's.
                $recorder->record('personal');
                $recorder->expiresAt(1.0);
                $own = $recorder->start();
                $recorder->record('own');
                $this->assertSame(['own' => [Freshness::Fresh, null]], $recorder->stop($own), 'the scope around is not its');
            });
            $recorder->record('page');
        });

        $this->assertNull($recorder->expiry($page));
        $this->assertSame(['page' => [Freshness::NextVisit, null]], $recorder->stop($page));
    }

    public function test_an_expiry_at_or_before_the_render_start_is_ignored_for_that_recording(): void
    {
        $recorder = new DependencyRecorder();
        $page = $recorder->start(1000.0);
        $part = $recorder->start(); // no start: every expiry counts
        $recorder->expiresAt(900);
        $recorder->expiresAt(1000);
        $recorder->expiresAt(1060);
        $this->assertSame(900.0, $recorder->expiry($part));
        $this->assertNull($recorder->ignoredExpiry($part));
        $recorder->stop($part);

        $this->assertSame(1060.0, $recorder->expiry($page), 'the future one, not the past ones');
        $this->assertSame(900.0, $recorder->ignoredExpiry($page));
        $recorder->stop($page);
    }
}
