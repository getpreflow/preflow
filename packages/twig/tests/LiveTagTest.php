<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\LiveParts;
use Preflow\Core\PageCache\LivePlaceholder;
use Preflow\Core\PageCache\PageCache;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateFunctionDefinition;

/** {% live with {…} %}…{% endlive %}: in place, as a placeholder while storing, and filled. */
final class LiveTagTest extends TestCase
{
    private string $dir;

    private PageCache $cache;

    private DependencyRecorder $recorder;

    private LiveParts $live;

    private TwigEngine $engine;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_live_twig_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->cache = new PageCache();
        $this->recorder = new DependencyRecorder();
        $this->live = new LiveParts(new LivePlaceholder('live-test-key'), $this->cache, $this->recorder, function (string $m): void {
            $this->logged[] = $m;
        });
        $this->engine = new TwigEngine([$this->dir], new AssetCollector(new NonceGenerator()), liveParts: $this->live);
        $this->engine->addGlobal('site', 'Holz');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_live_block_sees_only_its_with_values(): void
    {
        $this->template('page.twig', '<main>{{ secret }}|{% live with {number: object.number} %}[{{ number }}/{{ secret|default("none") }}/{{ object|default("none") }}/{{ site }}]{% endlive %}</main>');
        $context = ['secret' => 'outer', 'object' => ['number' => 7]];

        $this->assertSame('<main>outer|[7/none/none/Holz]</main>', $this->engine->render('page.twig', $context), 'in place');

        $stored = $this->storing(fn (): string => $this->engine->render('page.twig', $context));
        $this->assertStringNotContainsString('[7', $stored, 'not rendered while storing');
        $this->assertSame(1, preg_match('/^<main>outer\|(<!--pc:l:[A-Za-z0-9_-]+:[0-9a-f]{32}-->)<\/main>$/', $stored, $m));
        $this->assertSame([$m[1] => ['twig', ['t' => 'page.twig', 'b' => '__live_1', 'w' => ['number' => 7]]]], (new LivePlaceholder('live-test-key'))->find($stored));

        $this->assertSame('<main>outer|[7/none/none/Holz]</main>', $this->live->fill($stored), 'filled as in place');
    }

    public function test_live_blocks_in_layouts_loops_and_component_templates(): void
    {
        $this->template('layout.twig', '<body>{% block content %}{% endblock %}{% live %}<i>{{ who() }}</i>{% endlive %}</body>');
        $this->template('child.twig', '{% extends "layout.twig" %}{% block content %}{% for n in [1, 2] %}{% live with {n: n} %}<b>{{ n }}:{{ who() }}</b>{% endlive %}{% endfor %}{% endblock %}');
        $component = $this->template('Badge/Badge.twig', '<span>{% live with {id: id} %}#{{ id }} {{ who() }}{% endlive %}</span>');
        $who = 'Ann';
        $this->engine->addFunction(new TemplateFunctionDefinition('who', static function () use (&$who): string {
            return $who;
        }));

        $stored = $this->storing(fn (): string => $this->engine->render('child.twig') . $this->engine->render($component, ['id' => 5]));
        $this->assertSame(4, substr_count($stored, '<!--pc:l:'));
        $this->assertStringNotContainsString('Ann', $stored);

        $who = 'Bob';
        $this->assertSame('<body><b>1:Bob</b><b>2:Bob</b><i>Bob</i></body><span>#5 Bob</span>', $this->live->fill($stored));
        $who = 'Cy';
        $this->assertSame('<body><b>1:Cy</b><b>2:Cy</b><i>Cy</i></body><span>#5 Cy</span>', $this->live->fill($stored), 'every visitor their own');
    }

    public function test_with_values_are_scalars_and_arrays(): void
    {
        $this->template('page.twig', '{% live with {at: at} %}{{ at }}{% endlive %}');

        $this->expectException(\Twig\Error\RuntimeError::class);
        $this->expectExceptionMessage("'at' is DateTimeImmutable");
        $this->engine->render('page.twig', ['at' => new \DateTimeImmutable()]);
    }

    public function test_filling_records_nothing_and_refuses_nothing(): void
    {
        $this->template('page.twig', '<p>{% live %}{{ reads() }}{% endlive %}</p>');
        $this->engine->addFunction(new TemplateFunctionDefinition('reads', function (): string {
            $this->recorder->record('default:album#1');
            $this->cache->refuse('personal');

            return 'mine';
        }));
        $stored = $this->storing(fn (): string => $this->engine->render('page.twig'));

        $frame = $this->recorder->start();
        $key = $this->key();
        $before = $this->cache->begin($key);
        $this->assertSame('<p>mine</p>', $this->live->fill($stored));
        $this->assertNull($this->cache->refusal(), 'the page around it is not refused');
        $this->assertSame($key, $this->cache->key());
        $this->cache->end($before);
        $this->assertSame([], $this->recorder->stop($frame), 'its reads are not the page\'s');
    }

    public function test_a_block_that_throws_while_filling_is_its_boundary(): void
    {
        $this->template('page.twig', '<p>{% live %}{{ boom() }}{% endlive %}</p><p>rest</p>');
        $this->engine->addFunction(new TemplateFunctionDefinition('boom', static function (): string {
            throw new \RuntimeException('down');
        }));
        $stored = $this->storing(fn (): string => $this->engine->render('page.twig'));

        $this->assertSame('<p>' . LiveParts::ERROR . '</p><p>rest</p>', $this->live->fill($stored));
        $this->assertStringContainsString('page cache: a live part (twig) failed:', $this->logged[0] ?? '');
    }

    public function test_without_live_parts_the_tag_renders_in_place(): void
    {
        $this->template('page.twig', '{% live with {a: 1} %}{{ a }}{{ b|default("-") }}{% endlive %}');
        $engine = new TwigEngine([$this->dir], new AssetCollector(new NonceGenerator()));

        $this->assertSame('1-', $engine->render('page.twig', ['b' => 2]));
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
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $source);

        return $path;
    }
}
