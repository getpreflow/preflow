<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

/** {% region 'name' {attrs} %}…{% endregion %}: beyond the template engine contract (TwigContractTest). */
final class RegionTagTest extends TestCase
{
    private string $dir;

    private TwigEngine $engine;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_region_twig_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->engine = new TwigEngine([$this->dir], new AssetCollector(new NonceGenerator()), debug: true);
        $this->engine->addGlobal('site', 'Acme');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_regions_in_order_and_each_alone(): void
    {
        $this->write('page.twig', "{% region 'filters' %}F{{ n }}{% endregion %}-{% region 'results' %}R{{ n }}{% endregion %}");

        $this->assertSame(['filters', 'results'], $this->engine->regionsOf('page.twig'));
        $this->assertSame('<div id="c-filters">F1</div>-<div id="c-results">R1</div>', $this->engine->render('page.twig', ['componentId' => 'c', 'n' => 1]));
        $this->assertSame('R1', $this->engine->renderRegion('page.twig', 'results', ['componentId' => 'c', 'n' => 1]));
        $this->assertSame([], $this->engine->regionsOf($this->write('plain.twig', 'x')));
    }

    public function test_a_component_template_by_its_path(): void
    {
        $path = $this->write('Comp.twig', "<h1>{{ title }}</h1>{% region 'list' %}<ul>{{ site }}</ul>{% endregion %}");

        $this->assertSame('<h1>T</h1><div id="c9-list"><ul>Acme</ul></div>', $this->engine->render($path, ['componentId' => 'c9', 'title' => 'T']));
        $this->assertSame('<ul>Acme</ul>', $this->engine->renderRegion($path, 'list', ['componentId' => 'c9']), 'globals too');
    }

    public function test_attributes_are_escaped_true_prints_the_name_false_and_null_leave_it_out(): void
    {
        $this->write('a.twig', "{% region 'r' {class: cls, hidden: true, open: false, title: null, 'data-n': 3} %}x{% endregion %}");

        $this->assertSame('<div id="c-r" class="a&quot;&gt;&lt;b" hidden data-n="3">x</div>', $this->engine->render('a.twig', ['componentId' => 'c', 'cls' => 'a"><b']));
    }

    public function test_the_region_id_is_escaped(): void
    {
        $this->write('a.twig', "{% region 'r' %}x{% endregion %}");

        $this->assertSame('<div id="&quot;&gt;-r">x</div>', $this->engine->render('a.twig', ['componentId' => '">']));
    }

    public function test_a_region_without_component_id_fails(): void
    {
        $this->write('a.twig', "{% region 'r' %}x{% endregion %}");

        $this->expectExceptionMessage('componentId');
        $this->engine->render('a.twig');
    }

    public function test_an_id_attribute_is_refused(): void
    {
        $this->write('a.twig', "{% region 'r' {id: 'mine'} %}x{% endregion %}");

        $this->expectExceptionMessage("can't take the attribute 'id'");
        $this->engine->render('a.twig', ['componentId' => 'c']);
    }

    public function test_two_regions_of_one_name_fail_to_compile(): void
    {
        $this->write('a.twig', "{% region 'r' %}x{% endregion %}{% region 'r' %}y{% endregion %}");

        $this->expectException(\Twig\Error\SyntaxError::class);
        $this->expectExceptionMessage("two regions named 'r'");
        $this->engine->regionsOf('a.twig');
    }

    public function test_regions_do_not_nest(): void
    {
        $this->write('a.twig', "{% region 'a' %}{% region 'b' %}x{% endregion %}{% endregion %}");

        $this->expectException(\Twig\Error\SyntaxError::class);
        $this->expectExceptionMessage('nest');
        $this->engine->render('a.twig', ['componentId' => 'c']);
    }

    public function test_a_region_name_is_a_constant_name(): void
    {
        $this->write('a.twig', "{% region 'no space' %}x{% endregion %}");
        $this->write('b.twig', '{% region name %}x{% endregion %}');

        foreach (['a.twig', 'b.twig'] as $template) {
            try {
                $this->engine->render($template, ['componentId' => 'c', 'name' => 'x']);
                $this->fail($template . ' compiled');
            } catch (\Twig\Error\SyntaxError $e) {
                $this->assertStringContainsString('region', $e->getMessage());
            }
        }
    }

    public function test_a_template_block_cannot_take_a_region_block_name(): void
    {
        $this->write('a.twig', '{% block __region_72 %}x{% endblock %}');

        $this->expectException(\Twig\Error\SyntaxError::class);
        $this->expectExceptionMessage('reserved');
        $this->engine->render('a.twig');
    }

    public function test_a_region_inside_an_embed_is_refused(): void
    {
        $this->write('box.twig', '<box>{% block body %}{% endblock %}</box>');
        $this->write('a.twig', "{% embed 'box.twig' %}{% block body %}{% region 'r' %}x{% endregion %}{% endblock %}{% endembed %}");

        $this->expectException(\Twig\Error\SyntaxError::class);
        $this->expectExceptionMessage('embed');
        $this->engine->render('a.twig', ['componentId' => 'c']);
    }

    public function test_a_region_in_a_block_of_a_child_template(): void
    {
        $this->write('layout.twig', '<main>{% block content %}{% endblock %}</main>');
        $this->write('child.twig', "{% extends 'layout.twig' %}{% set n = 'set' %}{% block content %}{% set n = 'inner' %}{% region 'r' %}{{ n }}{% endregion %}{% endblock %}");

        $this->assertSame('<main><div id="c-r">ctx</div></main>', $this->engine->render('child.twig', ['componentId' => 'c', 'n' => 'ctx']));
        $this->assertSame('ctx', $this->engine->renderRegion('child.twig', 'r', ['componentId' => 'c', 'n' => 'ctx']));
    }

    public function test_an_included_template_renders_its_regions_with_its_own_context(): void
    {
        $this->write('part.twig', "{% region 'p' %}{{ n }}{% endregion %}");
        $this->write('a.twig', "{% set n = 'set' %}{% include 'part.twig' %}");

        $this->assertSame('<div id="c-p">set</div>', $this->engine->render('a.twig', ['componentId' => 'c', 'n' => 'ctx']), 'the include is rendered with n = set');
        $this->assertSame([], $this->engine->regionsOf('a.twig'), 'its regions are the partial\'s');
    }

    public function test_unknown_region_names_template_and_regions(): void
    {
        $this->write('a.twig', "{% region 'r' %}x{% endregion %}");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("The template a.twig has no region 'x'; its regions: r.");
        $this->engine->renderRegion('a.twig', 'x', ['componentId' => 'c']);
    }

    private function write(string $name, string $source): string
    {
        file_put_contents($this->dir . '/' . $name, $source);

        return $this->dir . '/' . $name;
    }
}
