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

require_once __DIR__ . '/Fixtures/Live/LiveBadge.php';
require_once __DIR__ . '/Fixtures/Live/LiveCard.php';
require_once __DIR__ . '/Fixtures/Live/LiveOnlyCard.php';
require_once __DIR__ . '/Fixtures/Live/LiveSpoofCard.php';
require_once __DIR__ . '/Fixtures/Live/LiveOuter.php';

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
        $this->live = new LiveParts(new LivePlaceholder('live-test-key-0123456789abcdef0123456789'), $this->cache, $this->recorder, function (string $m): void {
            $this->logged[] = $m;
        });
        $this->engine = new TwigEngine([$this->dir], new AssetCollector(new NonceGenerator()), liveParts: $this->live);
        $this->engine->addGlobal('site', 'Acme');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_live_block_sees_only_its_with_values(): void
    {
        $this->template('page.twig', '<main>{{ secret }}|{% live with {number: object.number} %}[{{ number }}/{{ secret|default("none") }}/{{ object|default("none") }}/{{ site }}]{% endlive %}</main>');
        $context = ['secret' => 'outer', 'object' => ['number' => 7]];

        $this->assertSame('<main>outer|[7/none/none/Acme]</main>', $this->engine->render('page.twig', $context), 'in place');

        $stored = $this->storing(fn (): string => $this->engine->render('page.twig', $context));
        $this->assertStringNotContainsString('[7', $stored, 'not rendered while storing');
        $this->assertSame(1, preg_match('/^<main>outer\|(<!--pc:l:[A-Za-z0-9_-]+:[0-9a-f]{32}-->)<\/main>$/', $stored, $m));
        $this->assertSame([$m[1] => ['twig', ['t' => 'page.twig', 'b' => '__live_1', 'w' => ['number' => 7]]]], (new LivePlaceholder('live-test-key-0123456789abcdef0123456789'))->find($stored));

        $this->assertSame('<main>outer|[7/none/none/Acme]</main>', $this->live->fill($stored), 'filled as in place');
    }

    public function test_live_blocks_in_layouts_loops_and_component_templates(): void
    {
        $this->template('layout.twig', '<body>{% block content %}{% endblock %}{% live %}<i>{{ who() }}</i>{% endlive %}</body>');
        $this->template('child.twig', '{% extends "layout.twig" %}{% block content %}{% for n in [1, 2] %}{% live with {n: n} %}<b>{{ n }}:{{ who() }}</b>{% endlive %}{% endfor %}{% endblock %}');
        $component = __DIR__ . '/Fixtures/Live/LiveBadge.twig';
        $who = 'Ann';
        $this->engine->addFunction(new TemplateFunctionDefinition('who', static function () use (&$who): string {
            return $who;
        }));

        $stored = $this->storing(fn (): string => $this->engine->render('child.twig') . $this->engine->render($component, ['id' => 5, 'componentClass' => \Preflow\Twig\Tests\Fixtures\Live\LiveBadge::class]));
        $this->assertSame(4, substr_count($stored, '<!--pc:l:'));
        $this->assertStringNotContainsString('Ann', $stored);

        $who = 'Bob';
        $this->assertSame('<body><b>1:Bob</b><b>2:Bob</b><i>Bob</i></body><span>#5 Bob</span>', $this->live->fill($stored));
        $who = 'Cy';
        $this->assertSame('<body><b>1:Cy</b><b>2:Cy</b><i>Cy</i></body><span>#5 Cy</span>', $this->live->fill($stored), 'every visitor their own');
    }

    public function test_a_string_template_renders_in_place_and_refuses_the_page(): void
    {
        $file = $this->template('loose.twig', '<p>{% live %}{{ "mine" }}{% endlive %}</p>');

        $before = $this->cache->begin($this->key());
        $html = $this->engine->render($file); // by path, but not a component's template
        $refusal = $this->cache->refusal();
        $this->cache->end($before);
        $this->assertSame(['<p>mine</p>', 'live:twig-template'], [$html, $refusal]);
    }

    public function test_only_declared_live_blocks_are_filled(): void
    {
        $this->template('page.twig', '{% live %}LIVE{% endlive %}{% block plain %}PLAIN{{ w|default("") }}{% endblock %}');
        $this->template('upload.txt', '{% live %}UPLOADED{% endlive %}');
        $sign = new LivePlaceholder('live-test-key-0123456789abcdef0123456789');
        $markers = [
            $sign->make('twig', ['t' => 'missing.twig', 'b' => '__live_1', 'w' => []]),
            $sign->make('twig', ['t' => 'page.twig', 'b' => 'plain', 'w' => ['w' => 'x']]),
            $sign->make('twig', ['t' => 'page.twig', 'b' => '__live_9', 'w' => []]),
            $sign->make('twig', ['t' => $this->dir . '/upload.txt', 'b' => '__live_1', 'w' => []]),
            $sign->make('twig', ['t' => '../' . basename($this->dir) . '/page.twig', 'b' => '__live_1', 'w' => []]),
            $sign->make('twig', ['c' => \stdClass::class, 'b' => '__live_1', 'w' => []]),
            $sign->make('twig', ['b' => '__live_1', 'w' => []]),
        ];
        $body = implode('|', $markers);

        $this->assertSame($body, $this->live->fill($body), 'every one stays a comment');
        $this->assertSame(['page cache: 7 live marker(s) left as comments: unsigned, forged or naming no declared live part'], $this->logged);
        $this->assertSame('LIVE', $this->live->fill($sign->make('twig', ['t' => 'page.twig', 'b' => '__live_1', 'w' => []])), 'the control');
    }

    public function test_with_an_unusable_key_nothing_is_placed_or_filled(): void
    {
        $this->template('page.twig', '<p>{% live %}LIVE{% endlive %}</p>');
        $live = new LiveParts(new LivePlaceholder(\Preflow\Core\Http\Csrf\StatelessCsrfToken::DEFAULT_KEY), $this->cache, $this->recorder, static function (): void {});
        $engine = new TwigEngine([$this->dir], new AssetCollector(new NonceGenerator()), liveParts: $live);

        $this->assertSame('<p>LIVE</p>', $this->storing(fn (): string => $engine->render('page.twig')), 'in place');
        $public = (new LivePlaceholder(\Preflow\Core\Http\Csrf\StatelessCsrfToken::DEFAULT_KEY))->make('twig', ['t' => 'page.twig', 'b' => '__live_1', 'w' => []]);
        $this->assertSame($public, $live->fill($public));
    }

    public function test_live_blocks_do_not_nest(): void
    {
        $this->template('page.twig', '{% live %}a{% live %}b{% endlive %}{% endlive %}');

        $this->expectException(\Twig\Error\SyntaxError::class);
        $this->expectExceptionMessage("can't be nested");
        $this->engine->render('page.twig');
    }

    public function test_live_blocks_do_not_nest_through_a_block_inside_them(): void
    {
        $this->template('page.twig', "{% live %}a\n{% block inner %}{% live %}b{% endlive %}{% endblock %}{% endlive %}");

        try {
            $this->engine->render('page.twig');
            $this->fail('a live block inside a block inside a live block compiles');
        } catch (\Twig\Error\SyntaxError $e) {
            $this->assertStringContainsString("can't be nested", $e->getMessage());
            $this->assertSame(2, $e->getTemplateLine(), 'the inner tag\'s line');
        }
    }

    public function test_live_blocks_do_not_nest_through_a_macro_defined_inside_them(): void
    {
        $this->template('page.twig', "{% live %}a{% macro m() %}\n{% live %}x{% endlive %}{% endmacro %}{% endlive %}");

        try {
            $this->engine->render('page.twig');
            $this->fail('a live block inside a macro inside a live block compiles');
        } catch (\Twig\Error\SyntaxError $e) {
            $this->assertStringContainsString("can't be nested", $e->getMessage());
            $this->assertSame(2, $e->getTemplateLine(), 'the inner tag\'s line');
        }
    }

    public function test_a_live_block_in_a_macro_outside_every_live_block_compiles(): void
    {
        $this->template('page.twig', '{% macro m() %}{% live %}x{% endlive %}{% endmacro %}{{ _self.m() }}');

        $this->assertSame('x', $this->engine->render('page.twig'));
    }

    public function test_a_live_block_in_an_embed_inside_a_live_block_is_its_own_template(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $this->template('page.twig', '{% live %}{% embed "box.twig" %}{% block body %}{% live %}in{% endlive %}{% endblock %}{% endembed %}{% endlive %}');

        $this->assertSame('<div>in</div>', $this->engine->render('page.twig'));
    }

    /** The recipe names the file and the block, never Twig's embedded index (random per compilation). */
    public function test_a_live_block_inside_an_embed_fills_from_the_page_cache(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $this->template('page.twig', "<main>{{ secret }}\n{% embed \"box.twig\" %}{% block body %}{% live with {n: n} %}[{{ n }}/{{ secret|default('none') }}/{{ who() }}]{% endlive %}{% endblock %}{% endembed %}</main>");
        $who = 'Ann';
        $this->engine->addFunction(new TemplateFunctionDefinition('who', static function () use (&$who): string {
            return $who;
        }));
        $context = ['secret' => 'outer', 'n' => 3];

        $this->assertSame("<main>outer\n<div>[3/none/Ann]</div></main>", $this->engine->render('page.twig', $context), 'in place');
        $stored = $this->storing(fn (): string => $this->engine->render('page.twig', $context));
        $this->assertStringNotContainsString('Ann', $stored);
        $this->assertSame([['twig', ['t' => 'page.twig', 'b' => '__live_2', 'w' => ['n' => 3]]]], array_values((new LivePlaceholder('live-test-key-0123456789abcdef0123456789'))->find($stored)));

        $who = 'Bob';
        $this->assertSame("<main>outer\n<div>[3/none/Bob]</div></main>", $this->live->fill($stored));
        $this->assertSame([], $this->logged);
    }

    public function test_live_blocks_in_two_embeds_of_one_template_fill_each_their_own(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $this->template('page.twig', implode("\n", [
            '{% embed "box.twig" %}{% block body %}{% live with {a: 1} %}A{{ a }}{% endlive %}{% endblock %}{% endembed %}',
            '{% embed "box.twig" %}{% block body %}{% live with {b: 2} %}B{{ b }}{% endlive %}{% endblock %}{% endembed %}{% embed "box.twig" %}{% block body %}{% live %}C{% endlive %}{% endblock %}{% endembed %}',
        ]));

        $stored = $this->storing(fn (): string => $this->engine->render('page.twig'));
        $blocks = array_map(static fn (array $p): string => $p[1]['b'], array_values((new LivePlaceholder('live-test-key-0123456789abcdef0123456789'))->find($stored)));

        $this->assertSame(['__live_1', '__live_2', '__live_2_2'], $blocks, 'names unique across the embeds of one file');
        $this->assertSame('<div>A1</div><div>B2</div><div>C</div>', $this->live->fill($stored));
        $this->assertSame($this->engine->render('page.twig'), $this->live->fill($stored), 'as in place');
    }

    public function test_a_live_block_inside_an_embed_inside_an_extends_fills(): void
    {
        $this->template('layout.twig', '<body>{% block content %}{% endblock %}{% live %}L{% endlive %}</body>');
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $this->template('child.twig', "{% extends \"layout.twig\" %}{% block content %}\n{% embed \"box.twig\" %}{% block body %}{% live with {n: 5} %}E{{ n }}{% endlive %}{% endblock %}{% endembed %}{% endblock %}");

        $stored = $this->storing(fn (): string => $this->engine->render('child.twig'));
        $this->assertSame(2, substr_count($stored, '<!--pc:l:'));
        $this->assertSame('<body><div>E5</div>L</body>', $this->live->fill($stored));
        $this->assertSame([], $this->logged);
    }

    public function test_a_live_block_inside_an_embed_inside_an_embed_fills(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $this->template('page.twig', '{% embed "box.twig" %}{% block body %}{% live %}1{% endlive %}{% embed "box.twig" %}{% block body %}{% live %}2{% endlive %}{% endblock %}{% endembed %}{% endblock %}{% endembed %}');

        $stored = $this->storing(fn (): string => $this->engine->render('page.twig'));
        $this->assertSame(2, substr_count($stored, '<!--pc:l:'));
        $this->assertSame('<div>1<div>2</div></div>', $this->live->fill($stored));
        $this->assertSame([], $this->logged);
    }

    public function test_a_live_block_inside_an_embed_of_a_component_template_fills(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $who = 'Ann';
        $this->engine->addFunction(new TemplateFunctionDefinition('who', static function () use (&$who): string {
            return $who;
        }));

        $render = fn (): string => $this->engine->render(__DIR__ . '/Fixtures/Live/LiveCard.twig', ['id' => 4, 'componentClass' => \Preflow\Twig\Tests\Fixtures\Live\LiveCard::class]);

        $stored = $this->storing($render);
        $this->assertSame(1, substr_count($stored, '<!--pc:l:'));
        $who = 'Bob';
        $this->assertSame('<div>#4 Bob</div>', $this->live->fill($stored));
        $this->assertSame($render(), $this->live->fill($stored), 'as in place');
        $this->assertSame([], $this->logged);
    }

    /** The component class doesn't come from the template's variables: an embed's `only` can't drop it. */
    public function test_a_live_block_inside_an_only_embed_of_a_component_template_fills(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $who = 'Ann';
        $this->engine->addFunction(new TemplateFunctionDefinition('who', static function () use (&$who): string {
            return $who;
        }));
        $render = fn (): string => $this->engine->render(__DIR__ . '/Fixtures/Live/LiveOnlyCard.twig', ['id' => 4, 'componentClass' => \Preflow\Twig\Tests\Fixtures\Live\LiveOnlyCard::class]);

        $before = $this->cache->begin($this->key());
        $stored = $render();
        $refusal = $this->cache->refusal();
        $this->cache->end($before);
        $this->assertNull($refusal, 'the page is stored');
        $this->assertSame([['twig', ['c' => \Preflow\Twig\Tests\Fixtures\Live\LiveOnlyCard::class, 'b' => '__live_1', 'w' => ['id' => 4]]]], array_values((new LivePlaceholder('live-test-key-0123456789abcdef0123456789'))->find($stored)));
        $who = 'Bob';
        $this->assertSame('<div>#4/none/Bob</div>', $this->live->fill($stored), 'the embed\'s with values don\'t reach the body');
        $this->assertSame($render(), $this->live->fill($stored), 'as in place');
        $this->assertSame([], $this->logged);
    }

    public function test_a_component_class_passed_by_a_template_does_not_change_the_recipe(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $class = \Preflow\Twig\Tests\Fixtures\Live\LiveSpoofCard::class;

        $stored = $this->storing(fn (): string => $this->engine->render(__DIR__ . '/Fixtures/Live/LiveSpoofCard.twig', ['id' => 6, 'componentClass' => $class]));
        $this->assertSame([['twig', ['c' => $class, 'b' => '__live_1', 'w' => ['id' => 6]]]], array_values((new LivePlaceholder('live-test-key-0123456789abcdef0123456789'))->find($stored)));
        $this->assertSame('<div>#6</div>', $this->live->fill($stored));
    }

    /** A component class whose own template is another file doesn't name this one: the block renders in place and the page is not stored. */
    public function test_a_component_class_that_does_not_own_the_template_names_nothing(): void
    {
        $this->engine->addFunction(new TemplateFunctionDefinition('who', static fn (): string => 'Ann'));
        $before = $this->cache->begin($this->key());
        $html = $this->engine->render(__DIR__ . '/Fixtures/Live/LiveBadge.twig', ['id' => 2, 'componentClass' => \Preflow\Twig\Tests\Fixtures\Live\LiveCard::class]);
        $refusal = $this->cache->refusal();
        $this->cache->end($before);

        $this->assertStringNotContainsString('<!--pc:l:', $html);
        $this->assertSame('live:twig-template', $refusal);
    }

    public function test_nested_components_each_name_their_own_class(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $this->engine->addFunction(new TemplateFunctionDefinition('who', static fn (): string => 'Ann'));
        $this->engine->addFunction(new TemplateFunctionDefinition('inner', fn (): string => $this->engine->render(__DIR__ . '/Fixtures/Live/LiveBadge.twig', ['id' => 5, 'componentClass' => \Preflow\Twig\Tests\Fixtures\Live\LiveBadge::class]), isSafe: true));
        $render = fn (): string => $this->engine->render(__DIR__ . '/Fixtures/Live/LiveOuter.twig', ['id' => 3, 'componentClass' => \Preflow\Twig\Tests\Fixtures\Live\LiveOuter::class]);

        $stored = $this->storing($render);
        $classes = array_map(static fn (array $p): string => $p[1]['c'] ?? '-', array_values((new LivePlaceholder('live-test-key-0123456789abcdef0123456789'))->find($stored)));

        $this->assertSame([\Preflow\Twig\Tests\Fixtures\Live\LiveBadge::class, \Preflow\Twig\Tests\Fixtures\Live\LiveOuter::class], $classes);
        $this->assertSame('<span>#5 Ann</span><div>O3</div>', $this->live->fill($stored));
        $this->assertSame($render(), $this->live->fill($stored), 'as in place');
        $this->assertSame([], $this->logged);
    }

    /** The embed's index is drawn per compilation; the recipe names the file and block, so either compilation fills the other's page. */
    public function test_two_compilations_of_one_source_fill_each_others_pages(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $this->template('page.twig', "<p>\n{% embed \"box.twig\" %}{% block body %}{% live with {n: 1} %}E{{ n }}{% endlive %}{% endblock %}{% endembed %}</p>");
        $other = new LiveParts(new LivePlaceholder('live-test-key-0123456789abcdef0123456789'), $this->cache, $this->recorder, function (string $m): void {
            $this->logged[] = $m;
        });
        $engine = new TwigEngine([$this->dir], new AssetCollector(new NonceGenerator()), debug: true, liveParts: $other); // other options: other compiled classes

        $indexes = [];
        foreach ([$this->engine, $engine] as $i => $each) {
            $twig = (new \ReflectionProperty(TwigEngine::class, 'twig'))->getValue($each);
            $code = $twig->compileSource($twig->getLoader()->getSourceContext('page.twig'));
            $this->assertSame(1, preg_match('/^class __TwigTemplate_\w+___(\d+) /m', $code, $m), "compilation {$i} has an embed");
            $indexes[$i] = $m[1];
            $this->assertStringContainsString('$this->load($this->getTemplateName(), 2, ' . $m[1] . ')->yieldBlock("__live_2", $context);', $code, "compilation {$i}'s own block names its own index");
        }
        $this->assertNotSame($indexes[0], $indexes[1]);

        $stored = $this->storing(fn (): string => $this->engine->render('page.twig'));
        $this->assertSame($stored, $this->storing(fn (): string => $engine->render('page.twig')), 'the same recipe from both');
        $this->assertSame("<p>\n<div>E1</div></p>", $other->fill($stored));
        $this->assertSame("<p>\n<div>E1</div></p>", $this->live->fill($stored));
        $this->assertSame([], $this->logged);
    }

    public function test_an_embeds_with_and_only_do_not_reach_the_live_body(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $this->template('page.twig', '{% embed "box.twig" with {secret: "x"} only %}{% block body %}{% live with {n: 2} %}{{ n }}/{{ secret|default("none") }}/{{ outer|default("none") }}{% endlive %}{% endblock %}{% endembed %}');

        $html = $this->engine->render('page.twig', ['outer' => 'o']);
        $this->assertSame('<div>2/none/none</div>', $html);
        $this->assertSame($html, $this->live->fill($this->storing(fn (): string => $this->engine->render('page.twig', ['outer' => 'o']))));
    }

    public function test_live_blocks_still_do_not_nest_inside_an_embed(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $this->template('page.twig', "{% embed \"box.twig\" %}{% block body %}{% live %}a\n{% live %}b{% endlive %}{% endlive %}{% endblock %}{% endembed %}");

        try {
            $this->engine->render('page.twig');
            $this->fail('a live block inside a live block inside an embed compiles');
        } catch (\Twig\Error\SyntaxError $e) {
            $this->assertStringContainsString("can't be nested", $e->getMessage());
            $this->assertSame(2, $e->getTemplateLine());
        }
    }

    /** Templates compiled before 0.82 call live() with the component class: it still renders, and says once that it is deprecated. */
    public function test_a_template_compiled_before_0_82_still_renders_through_live(): void
    {
        $this->template('page.twig', '{% live with {a: 1} %}[{{ a }}]{% endlive %}');
        $twig = (new \ReflectionProperty(TwigEngine::class, 'twig'))->getValue($this->engine);
        $template = $twig->load('page.twig')->unwrap();
        $extension = $twig->getExtension(\Preflow\Twig\LiveExtension::class);
        $context = ['componentClass' => 'Anything'];
        \Preflow\Core\Context\Deprecations::reset();
        $notices = [];
        set_error_handler(static function (int $no, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $html = [];
            foreach ([1, 2] as $_) {
                $html[] = $extension->live($twig, $template, '__live_1', $context['componentClass'] ?? null, ['a' => 1]); // as 0.81 compiled it
            }
            $stored = $this->storing(fn (): string => $extension->live($twig, $template, '__live_1', $context['componentClass'] ?? null, ['a' => 1]));
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['[1]', '[1]'], $html);
        $this->assertSame('[1]', $this->live->fill($stored), 'stored and filled as by part()');
        $this->assertSame(['Preflow\Twig\LiveExtension::live() is deprecated since 0.82 and is removed in 0.83; use part(), which templates compiled by 0.82 call (clear the Twig cache).'], array_values(array_unique($notices)));
        $this->assertCount(2, $notices, 'once per call site: the loop and the storing call');
    }

    public function test_live_blocks_on_one_line_get_their_own_names(): void
    {
        $this->template('page.twig', '{% live with {a: 1} %}[{{ a }}]{% endlive %}{% live with {a: 2} %}({{ a }}){% endlive %}{% live %}<3>{% endlive %}');

        $stored = $this->storing(fn (): string => $this->engine->render('page.twig'));
        $blocks = array_map(static fn (array $p): string => $p[1]['b'], array_values((new LivePlaceholder('live-test-key-0123456789abcdef0123456789'))->find($stored)));

        $this->assertSame(['__live_1', '__live_1_2', '__live_1_3'], $blocks);
        $this->assertSame('[1](2)<3>', $this->live->fill($stored));
    }

    /** Deprecations as failures: compiling and filling {% live %} (an embed's too) calls no deprecated Twig API (since 3.12: Parser::getBlockStack(), hasBlock()). */
    public function test_live_compiles_without_deprecated_twig_apis(): void
    {
        $this->template('box.twig', '<div>{% block body %}{% endblock %}</div>');
        $this->template('page.twig', "{% live %}a{% endlive %}{% live with {n: 1} %}{{ n }}{% endlive %}\n{% embed \"box.twig\" %}{% block body %}{% live %}e{% endlive %}{% endblock %}{% endembed %}");
        $this->template('nested.twig', '{% live %}a{% live %}b{% endlive %}{% endlive %}');
        $deprecations = [];
        set_error_handler(static function (int $no, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED | E_DEPRECATED);
        try {
            $html = $this->engine->render('page.twig');
            $filled = $this->live->fill($this->storing(fn (): string => $this->engine->render('page.twig')));
            try {
                $this->engine->render('nested.twig');
            } catch (\Twig\Error\SyntaxError) {
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame('a1<div>e</div>', $html);
        $this->assertSame($html, $filled, 'filled from the page cache, the embed\'s block too');
        $this->assertSame([], $deprecations);
    }

    public function test_the_live_block_prefix_is_reserved(): void
    {
        foreach (['{% block __live_3 %}x{% endblock %}', '{% live %}a{% endlive %}{% block __live_extra %}x{% endblock %}'] as $i => $source) {
            $this->template("reserved{$i}.twig", $source);
            try {
                $this->engine->render("reserved{$i}.twig");
                $this->fail("{$source} compiles");
            } catch (\Twig\Error\SyntaxError $e) {
                $this->assertStringContainsString('reserved for {% live %}', $e->getMessage());
            }
        }
        $this->template('fine.twig', '{% live %}a{% endlive %}{% block live_ok %}b{% endblock %}');
        $this->assertSame('ab', $this->engine->render('fine.twig'));
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
