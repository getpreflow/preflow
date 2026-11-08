<?php

declare(strict_types=1);

namespace Preflow\Blade\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Blade\BladeEngine;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\Freshness;
use Preflow\Core\PageCache\PageCache;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateFunctionDefinition;

/** @freshness … @endfreshness and @expires: the same contract as Twig's tags (FreshnessTagTest). */
final class FreshnessDirectiveTest extends TestCase
{
    private string $dir;

    private DependencyRecorder $recorder;

    private float $now = 1_800_000_000.0;

    private BladeEngine $engine;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_freshness_blade_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/cache', 0777, true);
        $this->recorder = new DependencyRecorder();
        $this->engine = $this->engine(new PageCache(recorder: $this->recorder, clock: fn (): float => $this->now));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_reads_inside_are_tagged_and_the_innermost_scope_wins(): void
    {
        $this->template('page.blade.php', "@read('default:job#1')"
            . "@freshness('next-visit')[@read('default:job')"
            . "@freshness('timed', every: '12h')@read('default:company')@endfreshness\n"
            . "@read('default:news')]@endfreshness\n"
            . "@freshness('timed', 1800)@read('default:weather')@endfreshness\n"
            . "@freshness('fresh')@read('default:fresh')@endfreshness");

        [$html, $tags] = $this->recorder->capture(fn (): string => str_replace("\n", '', $this->engine->render('page')));

        $this->assertSame('default:job#1[default:jobdefault:companydefault:news]default:weatherdefault:fresh', $html);
        $this->assertSame([
            'default:job#1' => [Freshness::Fresh, null],
            'default:job#' => [Freshness::Fresh, null],
            'default:job' => [Freshness::NextVisit, null],
            'default:company' => [Freshness::Timed, 43200],
            'default:news' => [Freshness::NextVisit, null],
            'default:weather' => [Freshness::Timed, 1800],
            'default:fresh' => [Freshness::Fresh, null],
        ], $tags);
    }

    public function test_the_strictest_freshness_wins_when_a_tag_is_read_twice(): void
    {
        $this->template('page.blade.php', "@freshness('timed', every: '2d')@read('default:job')@endfreshness\n"
            . "@freshness('next-visit')@read('default:job')@read('default:company')@endfreshness\n"
            . "@read('default:company')");

        [, $tags] = $this->recorder->capture(fn (): string => $this->engine->render('page'));

        $this->assertSame(['default:job' => [Freshness::NextVisit, null], 'default:company' => [Freshness::Fresh, null]], $tags);
    }

    public function test_the_body_sees_and_sets_the_variables_around_it_and_a_throw_closes_the_scope(): void
    {
        $this->template('page.blade.php', "@php(\$before = 'b')@freshness(\$name){{ \$before }}@php(\$inner = 'i')@read('default:job')@endfreshness{{ \$inner }}");

        [$html, $tags] = $this->recorder->capture(fn (): string => $this->engine->render('page', ['name' => 'next_visit']));

        $this->assertSame('bdefault:jobi', $html);
        $this->assertSame([Freshness::NextVisit, null], $tags['default:job']);

        $this->template('boom.blade.php', "@freshness('next-visit')@php(throw new \\RuntimeException('boom'))@endfreshness");
        $frame = $this->recorder->start();
        try {
            $this->engine->render('boom');
            $this->fail('rendered');
        } catch (\Throwable) {
        }
        $this->recorder->record('default:after');
        $this->assertSame([Freshness::Fresh, null], $this->recorder->stop($frame)['default:after'], 'the scope is closed');
    }

    public function test_expires_at_and_in_keep_the_earliest(): void
    {
        $this->template('page.blade.php', "@expires(at: \$event)@expires(in: '30m')@expires(at: \$none)@expires(at: '2030-01-01 00:00:00 UTC')x");

        $frame = $this->recorder->start();
        $html = $this->engine->render('page', ['event' => new \DateTimeImmutable('@' . (int) ($this->now + 7200)), 'none' => null]);
        $expiry = $this->recorder->expiry($frame);
        $this->recorder->stop($frame);

        $this->assertSame('x', $html);
        $this->assertSame($this->now + 1800, $expiry, '30 minutes is earlier than the event in 2 h');

        $frame = $this->recorder->start();
        $this->engine->render('page', ['event' => new \DateTimeImmutable('@' . (int) ($this->now + 60)), 'none' => null]);
        $this->assertSame($this->now + 60, $this->recorder->expiry($frame));
        $this->recorder->stop($frame);
    }

    public function test_outside_a_recording_nothing_is_recorded_and_the_body_renders(): void
    {
        $this->template('page.blade.php', "@freshness('next-visit')@read('default:job')@endfreshness\n@expires(in: '1h')");

        $this->assertSame("default:job", $this->engine->render('page'));
        $this->assertSame("default:job", $this->engine(null)->render('page'), 'without the page cache too');
    }

    /** @return iterable<string, array{string}> */
    public static function mistakes(): iterable
    {
        yield 'unknown name' => ["@freshness('soon')x @endfreshness"];
        yield 'timed without every' => ["@freshness('timed')x @endfreshness"];
        yield 'every on next visit' => ["@freshness('next-visit', every: '1h')x @endfreshness"];
        yield 'bad duration' => ["@freshness('timed', every: '1.5h')x @endfreshness"];
        yield 'zero duration' => ["@freshness('timed', every: '0m')x @endfreshness"];
        yield 'expires in a date' => ["@expires(in: 'tomorrow')"];
        yield 'expires at no date' => ["@expires(at: 'not a date at all')"];
        yield 'every a bool' => ["@freshness('timed', every: true)x @endfreshness"];
        yield 'expires in a bool' => ["@expires(in: true)"];
        yield 'expires at and in' => ["@expires(at: '2030-01-01', in: '1h')"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mistakes')]
    public function test_mistakes_fail_with_and_without_the_page_cache(string $source): void
    {
        $this->template('page.blade.php', $source);
        foreach ([$this->engine, $this->engine(null)] as $engine) {
            try {
                $engine->render('page');
                $this->fail('rendered: ' . $source);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious() ?? $e, $e->getMessage());
            }
        }
    }

    private function engine(?PageCache $cache): BladeEngine
    {
        $engine = new BladeEngine([$this->dir], new AssetCollector(new NonceGenerator()), cachePath: $this->dir . '/cache', pageCache: $cache);
        $engine->addFunction(new TemplateFunctionDefinition('read', function (string $tag): string {
            $this->recorder->record($tag);

            return $tag;
        }));

        return $engine;
    }

    private function template(string $name, string $source): void
    {
        file_put_contents($this->dir . '/' . $name, $source);
    }
}
