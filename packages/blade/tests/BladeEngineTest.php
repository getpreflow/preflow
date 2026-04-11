<?php

declare(strict_types=1);

namespace Preflow\Blade\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Blade\BladeEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
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
}
