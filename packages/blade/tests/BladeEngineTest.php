<?php

declare(strict_types=1);

namespace Preflow\Blade\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Blade\BladeEngine;
use Preflow\View\AssetCollector;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\Seo\SeoContext;
use Preflow\View\TemplateFunctionDefinition;

final class BladeEngineTest extends TestCase
{
    private BladeEngine $engine;
    private string $tmpDir;
    private AssetCollector $assets;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_blade_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        mkdir($this->tmpDir . '/cache', 0755, true);

        $this->assets = new AssetCollector(new NonceGenerator());
        $this->engine = new BladeEngine(
            templateDirs: [$this->tmpDir],
            assetCollector: $this->assets,
            cachePath: $this->tmpDir . '/cache',
        );
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmpDir);
    }

    public function test_renders_simple_template(): void
    {
        file_put_contents($this->tmpDir . '/hello.blade.php', 'Hello, {{ $name }}!');

        $html = $this->engine->render('hello', ['name' => 'World']);

        $this->assertSame('Hello, World!', $html);
    }

    public function test_file_page_paths_relative_to_a_template_folder_render(): void
    {
        // What file-based routing passes for app/pages/about.blade.php and app/pages/blog/[slug].blade.php.
        mkdir($this->tmpDir . '/blog');
        file_put_contents($this->tmpDir . '/_layout.blade.php', '<main>@yield(\'content\')</main>');
        file_put_contents($this->tmpDir . '/about.blade.php', "@extends('_layout')\n@section('content')About {{ \$name }}@endsection");
        file_put_contents($this->tmpDir . '/blog/[slug].blade.php', 'Post {{ $route->slug }}');
        file_put_contents($this->tmpDir . '/v1.2.blade.php', 'Version');

        $this->assertSame('<main>About us</main>', trim($this->engine->render('about.blade.php', ['name' => 'us'])));
        $this->assertSame('Post first', $this->engine->render('blog/[slug].blade.php', ['route' => (object) ['slug' => 'first']]));
        $this->assertSame('Version', $this->engine->render('v1.2.blade.php'));
        $this->assertTrue($this->engine->exists('about.blade.php'));
        $this->assertTrue($this->engine->exists('blog/[slug].blade.php'));
        $this->assertFalse($this->engine->exists('missing.blade.php'));
        // View names keep working.
        $this->assertSame('<main>About us</main>', trim($this->engine->render('about', ['name' => 'us'])));
    }

    public function test_absolute_paths_render_their_own_file_when_names_collide(): void
    {
        mkdir($this->tmpDir . '/a');
        mkdir($this->tmpDir . '/b');
        file_put_contents($this->tmpDir . '/a/header.blade.php', 'Header A {{ $n }}');
        file_put_contents($this->tmpDir . '/b/header.blade.php', 'Header B {{ $n }}');

        // Both orders: whichever folder became a location first must not win the name.
        $this->assertSame('Header A 1', $this->engine->render($this->tmpDir . '/a/header.blade.php', ['n' => 1]));
        $this->assertSame('Header B 2', $this->engine->render($this->tmpDir . '/b/header.blade.php', ['n' => 2]));
        $this->assertSame('Header A 3', $this->engine->render($this->tmpDir . '/a/header.blade.php', ['n' => 3]));
    }

    public function test_escapes_output_by_default(): void
    {
        file_put_contents($this->tmpDir . '/escape.blade.php', '{{ $content }}');

        $html = $this->engine->render('escape', ['content' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_raw_output(): void
    {
        file_put_contents($this->tmpDir . '/raw.blade.php', '{!! $content !!}');

        $html = $this->engine->render('raw', ['content' => '<strong>bold</strong>']);

        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function test_exists_returns_true_for_existing_template(): void
    {
        file_put_contents($this->tmpDir . '/exists.blade.php', 'yes');

        $this->assertTrue($this->engine->exists('exists'));
    }

    public function test_exists_returns_false_for_missing_template(): void
    {
        $this->assertFalse($this->engine->exists('nonexistent'));
    }

    public function test_extends_and_sections(): void
    {
        file_put_contents($this->tmpDir . '/_layout.blade.php', '<html>@yield("content")</html>');
        file_put_contents($this->tmpDir . '/page.blade.php', <<<'BLADE'
            @extends("_layout")
            @section("content")
            Hello
            @endsection
            BLADE);

        $html = $this->engine->render('page');

        $this->assertStringContainsString('<html>', $html);
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function test_if_directive(): void
    {
        file_put_contents($this->tmpDir . '/cond.blade.php', "@if(\$show)\nvisible\n@endif");

        $html = $this->engine->render('cond', ['show' => true]);
        $this->assertStringContainsString('visible', $html);

        $html = $this->engine->render('cond', ['show' => false]);
        $this->assertStringNotContainsString('visible', $html);
    }

    public function test_foreach_directive(): void
    {
        file_put_contents($this->tmpDir . '/loop.blade.php', '@foreach($items as $item){{ $item }}@endforeach');

        $html = $this->engine->render('loop', ['items' => ['a', 'b', 'c']]);

        $this->assertSame('abc', $html);
    }

    public function test_add_function_registers_directive(): void
    {
        $this->engine->addFunction(new TemplateFunctionDefinition(
            name: 'greet',
            callable: fn (string $name) => "Hello, {$name}!",
            isSafe: true,
        ));

        file_put_contents($this->tmpDir . '/func.blade.php', '@greet("World")');

        $html = $this->engine->render('func');

        $this->assertStringContainsString('Hello, World!', $html);
    }

    public function test_add_global_makes_variable_available(): void
    {
        $this->engine->addGlobal('siteName', 'Preflow');

        file_put_contents($this->tmpDir . '/global.blade.php', '{{ $siteName }}');

        $html = $this->engine->render('global');

        $this->assertStringContainsString('Preflow', $html);
    }

    public function test_get_template_extension_returns_blade_php(): void
    {
        $this->assertSame('blade.php', $this->engine->getTemplateExtension());
    }

    public function test_css_directive_feeds_asset_collector(): void
    {
        file_put_contents($this->tmpDir . '/styled.blade.php', '@css .test { color: red; } @endcss<div>content</div>');

        $this->engine->render('styled');

        $css = $this->assets->renderCss();
        $this->assertStringContainsString('.test { color: red; }', $css);
    }

    public function test_geo_directive_outputs_nothing_and_records_trimmed_markdown(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);
        $engine = new BladeEngine([$this->tmpDir], $this->assets, cachePath: $this->tmpDir . '/cache', geoCollector: $geo);
        file_put_contents($this->tmpDir . '/geo.blade.php', "<p>Page</p>@geo\n  ## {{ \$name }}\n@endgeo");

        $html = $engine->render('geo', ['name' => "Tom & Jerry's <b>"]);

        $this->assertSame('<p>Page</p>', trim($html));
        $this->assertSame(["## Tom & Jerry's <b>"], $geo->pageBlocks());
    }

    public function test_geo_directive_records_nothing_while_not_capturing(): void
    {
        $geo = new GeoCollector();
        $engine = new BladeEngine([$this->tmpDir], $this->assets, cachePath: $this->tmpDir . '/cache', geoCollector: $geo);
        file_put_contents($this->tmpDir . '/geo-off.blade.php', '<p>Page</p>@geo ## Title @endgeo');

        $this->assertSame('<p>Page</p>', trim($engine->render('geo-off')));
        $this->assertSame([], $geo->pageBlocks());
    }

    public function test_geo_directive_without_collector_outputs_nothing(): void
    {
        file_put_contents($this->tmpDir . '/geo-none.blade.php', '<p>Page</p>@geo ## Title @endgeo');

        $this->assertSame('<p>Page</p>', trim($this->engine->render('geo-none')));
    }

    public function test_qr_svg_function_renders_svg(): void
    {
        $this->engine->addFunction(
            (new \Preflow\View\ViewExtensionProvider())->getTemplateFunctions()[0],
        );
        file_put_contents($this->tmpDir . '/qr.blade.php', "@qr_svg('https://x.test')");

        $this->assertStringContainsString('<svg', $this->engine->render('qr'));
    }

    public function test_seo_directive_merges_into_the_context_and_prints_nothing(): void
    {
        $seo = new SeoContext();
        $engine = new BladeEngine([$this->tmpDir], $this->assets, cachePath: $this->tmpDir . '/cache', seoContext: $seo);
        file_put_contents(
            $this->tmpDir . '/seo.blade.php',
            "<p>Page</p>@seo(['title' => \$title, 'og_type' => 'article', 'schema' => ['@type' => 'Event']])@seo(['noindex' => true])",
        );

        $this->assertSame('<p>Page</p>', trim($engine->render('seo', ['title' => 'Game night'])));
        $meta = $seo->get();
        $this->assertSame('Game night', $meta?->title);
        $this->assertSame('article', $meta->ogType);
        $this->assertTrue($meta->noindex);
        $this->assertSame([['@type' => 'Event']], $meta->schemas);
    }

    public function test_seo_directive_without_context_is_a_no_op(): void
    {
        file_put_contents($this->tmpDir . '/seo-none.blade.php', "<p>Page</p>@seo(['title' => 'X'])");

        $this->assertSame('<p>Page</p>', trim($this->engine->render('seo-none')));
    }

    public function test_an_http_exception_thrown_while_rendering_comes_out_as_itself(): void
    {
        $this->engine->addFunction(new TemplateFunctionDefinition(
            name: 'refuse',
            callable: static fn (): string => throw new \Preflow\Core\Exceptions\ForbiddenHttpException('Members only'),
        ));
        file_put_contents($this->tmpDir . '/layout.blade.php', '<main>@yield(\'content\')</main>');
        file_put_contents($this->tmpDir . '/page.blade.php', "@extends('layout')\n@section('content')@refuse()@endsection");

        foreach (['page', 'page.blade.php', $this->tmpDir . '/page.blade.php'] as $template) {
            try {
                $this->engine->render($template);
                $this->fail('expected the HttpException for ' . $template);
            } catch (\Preflow\Core\Exceptions\ForbiddenHttpException $e) {
                $this->assertSame('Members only', $e->getMessage());
            }
        }
    }
}

