<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\Duration;
use Preflow\Core\PageCache\Freshness;
use Preflow\Core\PageCache\PageCache;

/** The names, durations and PageCache calls the templates and components share. */
final class FreshnessDialTest extends TestCase
{
    public function test_names(): void
    {
        $this->assertSame(Freshness::NextVisit, Freshness::named('next-visit'));
        $this->assertSame(Freshness::NextVisit, Freshness::named(' Next_Visit '));
        $this->assertSame(Freshness::Fresh, Freshness::named('fresh'));
        $this->assertSame(Freshness::Timed, Freshness::named(Freshness::Timed));
        $this->expectException(\InvalidArgumentException::class);
        Freshness::named('soon');
    }

    public function test_durations(): void
    {
        $this->assertSame(1800, Duration::seconds('30m'));
        $this->assertSame(43200, Duration::seconds('12h'));
        $this->assertSame(172800, Duration::seconds('2d'));
        $this->assertSame(45, Duration::seconds('45s'));
        $this->assertSame(90, Duration::seconds('90'));
        $this->assertSame(90, Duration::seconds(90));
        $this->assertSame(7200, Duration::seconds(' 2H '));
    }

    /** @return iterable<string, array{int|string}> */
    public static function notDurations(): iterable
    {
        foreach (['0m', '0', '-1h', '1.5h', '1w', 'soon', '', '12 hours', '99999999999999999999d', '1234567890s'] as $value) {
            yield $value === '' ? 'empty' : $value => [$value];
        }
        yield 'zero' => [0];
        yield 'negative' => [-60];
    }

    #[DataProvider('notDurations')]
    public function test_not_durations(int|string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Duration::seconds($value);
    }

    public function test_page_cache_within_and_expiry(): void
    {
        $recorder = new DependencyRecorder();
        $cache = new PageCache(recorder: $recorder, clock: static fn (): float => 1000.0);
        $frame = $recorder->start();
        $result = $cache->within(Freshness::NextVisit, null, function () use ($recorder, $cache): string {
            $recorder->record('other jobs');
            $cache->expires(in: '1h');
            $cache->expires(at: 5000);
            $cache->expires(at: null); // an event without a date

            return 'ok';
        });
        $cache->expiresAt(new \DateTimeImmutable('@4000'));

        $this->assertSame('ok', $result);
        $this->assertSame(4000.0, $recorder->expiry($frame));
        $this->assertSame(['other jobs' => [Freshness::NextVisit, null]], $recorder->stop($frame));
    }

    public function test_without_a_recorder_it_runs_and_still_checks(): void
    {
        $cache = new PageCache();
        $this->assertSame('ok', $cache->within(Freshness::NextVisit, 99, fn (): string => 'ok'), 'seconds only count for timed');
        $cache->expiresAt(new \DateTimeImmutable());
        $cache->enter('timed', '12h');
        $cache->leave();

        $this->expectException(\InvalidArgumentException::class);
        $cache->within(Freshness::Timed, null, fn (): string => 'not run');
    }

    /** @return iterable<string, array{\Closure(PageCache): void}> */
    public static function mistakes(): iterable
    {
        yield 'timed without every' => [static fn (PageCache $c) => $c->enter('timed')];
        yield 'every on next visit' => [static fn (PageCache $c) => $c->enter('next-visit', '1h')];
        yield 'unknown name' => [static fn (PageCache $c) => $c->enter('sometimes')];
        yield 'at and in' => [static fn (PageCache $c) => $c->expires(at: 5, in: '1h')];
        yield 'at no date' => [static fn (PageCache $c) => $c->expires(at: 'not a date at all')];
        yield 'at an array' => [static fn (PageCache $c) => $c->expires(at: [])];
        yield 'in no duration' => [static fn (PageCache $c) => $c->expires(in: 'later')];
        yield 'in a bool' => [static fn (PageCache $c) => $c->expires(in: true)];
        yield 'every a bool' => [static fn (PageCache $c) => $c->enter('timed', true)];
        yield 'name a bool' => [static fn (PageCache $c) => $c->enter(true)];
        yield 'at a bool' => [static fn (PageCache $c) => $c->expires(at: true)];
        yield 'an overflowing duration' => [static fn (PageCache $c) => $c->enter('timed', '99999999999999999999d')];
    }

    #[DataProvider('mistakes')]
    public function test_mistakes(\Closure $call): void
    {
        foreach ([new PageCache(), new PageCache(recorder: new DependencyRecorder())] as $cache) {
            try {
                $call($cache);
                $this->fail('accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_address_tags_are_exact_and_normalised(): void
    {
        $tag = \Preflow\Core\PageCache\DataTags::address('/Jobs/Carpenter%20A/');
        $this->assertSame('~address:/jobs/carpenter a', $tag);
        $this->assertSame('~address:/', \Preflow\Core\PageCache\DataTags::address(''));
        $this->assertSame('~address:/a%23b', \Preflow\Core\PageCache\DataTags::address('/a#b'), 'never read as a record');
        $this->assertSame([$tag], \Preflow\Core\PageCache\DataTags::changes($tag), 'changed as it is');
        $this->assertSame([$tag], \Preflow\Core\PageCache\DataTags::dependencies($tag));
    }

    public function test_depends_records_under_the_scope_and_maps_the_storage(): void
    {
        $recorder = new DependencyRecorder();
        $cache = new PageCache(storageName: static fn (string $s): string => $s === 'mysql' ? 'default' : $s, recorder: $recorder);
        $cache->depends('menu'); // not recording: nothing
        $frame = $recorder->start();
        $cache->depends('menu', 'mysql:job#5', \Preflow\Core\PageCache\DataTags::address('/x'));
        $cache->within(Freshness::NextVisit, null, fn () => $cache->depends('weather'));

        $this->assertSame([
            'menu' => [Freshness::Fresh, null],
            'default:job#5' => [Freshness::Fresh, null],
            'default:job#' => [Freshness::Fresh, null],
            '~address:/x' => [Freshness::Fresh, null],
            'weather' => [Freshness::NextVisit, null],
        ], $recorder->stop($frame));
    }
}
