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
}
