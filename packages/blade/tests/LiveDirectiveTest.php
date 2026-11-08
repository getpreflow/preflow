<?php

declare(strict_types=1);

namespace Preflow\Blade\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Blade\BladeEngine;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\LiveParts;
use Preflow\Core\PageCache\LivePlaceholder;
use Preflow\Core\PageCache\PageCache;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateFunctionDefinition;

/** @live([…]) … @endlive: the same contract as Twig's {% live %} (LiveTagTest). */
final class LiveDirectiveTest extends TestCase
{
    private string $dir;

    private PageCache $cache;

    private DependencyRecorder $recorder;

    private LiveParts $live;

    private BladeEngine $engine;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_live_blade_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/cache', 0777, true);
        $this->cache = new PageCache();
        $this->recorder = new DependencyRecorder();
        $this->live = new LiveParts(new LivePlaceholder('live-test-key'), $this->cache, $this->recorder, function (string $m): void {
            $this->logged[] = $m;
        });
        $this->engine = $this->engine();
        $this->engine->addGlobal('site', 'Holz');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_live_block_sees_only_its_with_values(): void
    {
        $file = $this->template('page.blade.php', "<main>{{ \$secret }}|@live(['number' => \$object['number']])[{{ \$number }}/{{ \$secret ?? 'none' }}/{{ isset(\$object) ? 'object' : 'none' }}/{{ \$site }}]@endlive</main>");
        $context = ['secret' => 'outer', 'object' => ['number' => 7]];

        $this->assertSame('<main>outer|[7/none/none/Holz]</main>', $this->engine->render('page', $context), 'in place');

        $stored = $this->storing(fn (): string => $this->engine->render('page', $context));
        $this->assertStringNotContainsString('[7', $stored, 'not rendered while storing');
        $this->assertSame(1, preg_match('/^<main>outer\|(<!--pc:l:[A-Za-z0-9_-]+:[0-9a-f]{32}-->)<\/main>$/', $stored, $m));
        $found = (new LivePlaceholder('live-test-key'))->find($stored)[$m[1]] ?? null;
        $this->assertSame('blade', $found[0] ?? null);
        $this->assertSame(['f' => realpath($file), 'i' => 0, 'w' => ['number' => 7]], array_diff_key($found[1] ?? [], ['h' => true]));

        $this->assertSame('<main>outer|[7/none/none/Holz]</main>', $this->live->fill($stored), 'filled as in place');
    }

    public function test_live_blocks_in_layouts_loops_and_after_a_cleared_cache(): void
    {
        $this->template('layout.blade.php', "<body>@yield('content')@live<i>{{ \$who() }}</i>@endlive</body>");
        $this->template('child.blade.php', "@extends('layout')@section('content')@foreach ([1, 2] as \$n)@live(['n' => \$n])<b>{{ \$n }}:{{ \$who() }}</b>@endlive @endforeach @endsection");
        $who = 'Ann';
        $this->engine->addGlobal('who', static function () use (&$who): string {
            return $who;
        });

        $stored = $this->storing(fn (): string => $this->engine->render('child'));
        $this->assertSame(3, substr_count($stored, '<!--pc:l:'));
        $this->assertStringNotContainsString('Ann', $stored);

        $who = 'Bob';
        $inPlace = $this->engine->render('child');
        $this->assertStringContainsString('<b>1:Bob</b> <b>2:Bob</b>', $inPlace);
        $this->assertSame($inPlace, $this->live->fill($stored));

        // A deploy's cache:clear: the bodies are written again from the templates.
        exec('rm -rf ' . escapeshellarg($this->dir . '/cache') . ' && mkdir ' . escapeshellarg($this->dir . '/cache'));
        $who = 'Cy';
        $this->assertSame(str_replace('Bob', 'Cy', $inPlace), $this->live->fill($stored));
    }

    public function test_with_values_are_scalars_and_arrays(): void
    {
        $this->template('page.blade.php', "@live(['at' => \$at]){{ 'x' }}@endlive");

        $this->expectExceptionMessage("'at' is DateTimeImmutable");
        $this->engine->render('page', ['at' => new \DateTimeImmutable()]);
    }

    public function test_filling_records_nothing_and_refuses_nothing(): void
    {
        $this->template('page.blade.php', '<p>@live{{ $reads() }}@endlive</p>');
        $this->engine->addGlobal('reads', function (): string {
            $this->recorder->record('default:album#1');
            $this->cache->refuse('personal');

            return 'mine';
        });
        $stored = $this->storing(fn (): string => $this->engine->render('page'));

        $frame = $this->recorder->start();
        $before = $this->cache->begin($this->key());
        $this->assertSame('<p>mine</p>', $this->live->fill($stored));
        $this->assertNull($this->cache->refusal());
        $this->cache->end($before);
        $this->assertSame([], $this->recorder->stop($frame));
    }

    public function test_a_block_that_throws_while_filling_is_its_boundary(): void
    {
        $this->template('page.blade.php', '<p>@live{{ $boom() }}@endlive</p><p>rest</p>');
        $this->engine->addGlobal('boom', static function (): string {
            throw new \RuntimeException('down');
        });
        $stored = $this->storing(fn (): string => $this->engine->render('page'));

        $this->assertSame('<p>' . LiveParts::ERROR . '</p><p>rest</p>', $this->live->fill($stored));
        $this->assertStringContainsString('page cache: a live part (blade) failed:', $this->logged[0] ?? '');
    }

    public function test_without_live_parts_and_lookalike_directives(): void
    {
        $this->template('page.blade.php', "@live(['a' => 1]){{ \$a }}{{ \$b ?? '-' }}@endlive|@@live|{{ 'livewire' }}");
        $engine = new BladeEngine([$this->dir], new AssetCollector(new NonceGenerator()), cachePath: $this->dir . '/cache2');

        $this->assertSame('1-|@live|livewire', $engine->render('page', ['b' => 2]));
    }

    public function test_nested_live_blocks_are_refused(): void
    {
        $this->template('page.blade.php', '@live a @live b @endlive @endlive');

        $this->expectExceptionMessage("can't be nested");
        $this->engine->render('page');
    }

    private function engine(): BladeEngine
    {
        return new BladeEngine([$this->dir], new AssetCollector(new NonceGenerator()), cachePath: $this->dir . '/cache', liveParts: $this->live);
    }

    /** Runs $render as a page rendered for the page cache. */
    private function storing(\Closure $render): string
    {
        $before = $this->cache->begin($this->key());
        try {
            return $render();
        } finally {
            $this->cache->end($before);
        }
    }

    private function key(): PageKey
    {
        return PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/page'), new PageCacheConfig(enabled: true, hosts: ['example.com']))
            ?? throw new \RuntimeException('no key');
    }

    private function template(string $name, string $source): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $source);

        return $path;
    }
}
