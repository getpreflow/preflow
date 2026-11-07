<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateFunctionDefinition;
use Preflow\Twig\TwigEngine;

final class TwigEngineTest extends TestCase
{
    private string $templateDir;
    private TwigEngine $engine;

    protected function setUp(): void
    {
        $this->templateDir = sys_get_temp_dir() . '/preflow_twig_test_' . uniqid();
        mkdir($this->templateDir, 0755, true);
        $this->engine = $this->makeEngine();
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->templateDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createTemplate(string $name, string $content): void
    {
        $path = $this->templateDir . '/' . $name;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($path, $content);
    }

    private function makeEngine(): TwigEngine
    {
        $assets = new AssetCollector(new NonceGenerator());
        return new TwigEngine([$this->templateDir], $assets);
    }

    public function test_renders_simple_template(): void
    {
        $this->createTemplate('hello.twig', '<h1>Hello {{ name }}</h1>');

        $result = $this->engine->render('hello.twig', ['name' => 'World']);

        $this->assertSame('<h1>Hello World</h1>', $result);
    }

    public function test_exists_returns_true_for_existing(): void
    {
        $this->createTemplate('page.twig', 'content');

        $this->assertTrue($this->engine->exists('page.twig'));
    }

    public function test_exists_returns_false_for_missing(): void
    {
        $this->assertFalse($this->engine->exists('nonexistent.twig'));
    }

    public function test_implements_template_engine_interface(): void
    {
        $this->assertInstanceOf(\Preflow\View\TemplateEngineInterface::class, $this->engine);
    }

    public function test_template_with_css_block(): void
    {
        $this->createTemplate('styled.twig',
            '<div>content</div>{% apply css %}.box { color: red; }{% endapply %}'
        );

        $result = $this->engine->render('styled.twig');

        // CSS block returns empty, content is rendered
        $this->assertStringContainsString('<div>content</div>', $result);
        $this->assertStringNotContainsString('.box', $result); // CSS not in output, it's in collector
    }

    public function test_template_with_extends(): void
    {
        $this->createTemplate('_layout.twig',
            '<html><body>{% block content %}{% endblock %}</body></html>'
        );
        $this->createTemplate('page.twig',
            '{% extends "_layout.twig" %}{% block content %}<p>Hello</p>{% endblock %}'
        );

        $result = $this->engine->render('page.twig');

        $this->assertStringContainsString('<html><body><p>Hello</p></body></html>', $result);
    }

    public function test_multiple_template_dirs(): void
    {
        $secondDir = sys_get_temp_dir() . '/preflow_twig_test2_' . uniqid();
        mkdir($secondDir, 0755, true);
        file_put_contents($secondDir . '/other.twig', 'from second dir');

        $assets = new AssetCollector(new NonceGenerator());
        $engine = new TwigEngine([$this->templateDir, $secondDir], $assets);

        $result = $engine->render('other.twig');
        $this->assertSame('from second dir', $result);

        // Cleanup
        unlink($secondDir . '/other.twig');
        rmdir($secondDir);
    }

    public function test_add_function_makes_function_callable(): void
    {
        $this->engine->addFunction(new TemplateFunctionDefinition(
            name: 'greet',
            callable: fn (string $name) => "Hello, {$name}!",
            isSafe: true,
        ));

        $this->createTemplate('test_func.twig', "{{ greet('World') }}");

        $tpl = $this->engine->render('test_func.twig');

        $this->assertStringContainsString('Hello, World!', $tpl);
    }

    public function test_add_function_escapes_by_default(): void
    {
        $this->engine->addFunction(new TemplateFunctionDefinition(
            name: 'raw',
            callable: fn () => '<strong>bold</strong>',
        ));

        $this->createTemplate('test_raw.twig', '{{ raw() }}');

        $tpl = $this->engine->render('test_raw.twig');

        $this->assertStringContainsString('&lt;strong&gt;', $tpl);
    }

    public function test_add_global_makes_variable_available(): void
    {
        $this->engine->addGlobal('siteName', 'Preflow');

        $this->createTemplate('test_global.twig', '{{ siteName }}');

        $tpl = $this->engine->render('test_global.twig');

        $this->assertStringContainsString('Preflow', $tpl);
    }

    public function test_get_template_extension_returns_twig(): void
    {
        $this->assertSame('twig', $this->engine->getTemplateExtension());
    }

    public function test_get_twig_does_not_exist(): void
    {
        $this->assertFalse(method_exists($this->engine, 'getTwig'));
    }

    public function test_qr_svg_function_renders_svg(): void
    {
        $this->engine->addFunction(
            (new \Preflow\View\ViewExtensionProvider())->getTemplateFunctions()[0],
        );
        $this->createTemplate('qr.twig', "{{ qr_svg('https://x.test') }}");

        $this->assertStringContainsString('<svg', $this->engine->render('qr.twig'));
    }

    private function cachedEngine(string $cache): TwigEngine
    {
        return new TwigEngine([$this->templateDir], new AssetCollector(new NonceGenerator()), cachePath: $cache);
    }

    /** @return string[] */
    private function compiled(string $cache): array
    {
        return glob($cache . '/*/*.php') ?: [];
    }

    public function test_twig_compiles_into_the_cache_directory(): void
    {
        $cache = $this->templateDir . '/cache/twig';
        $this->createTemplate('hello.twig', '<h1>Hello {{ name }}</h1>');
        $engine = $this->cachedEngine($cache);

        $engine->render('hello.twig', ['name' => 'A']);
        $files = $this->compiled($cache);
        $mtimes = array_map('filemtime', $files);
        clearstatcache();
        $engine->render('hello.twig', ['name' => 'B']);
        // A fresh engine (next request) reuses the compiled file instead of compiling again.
        $this->cachedEngine($cache)->render('hello.twig', ['name' => 'C']);

        $this->assertNotEmpty($files);
        $this->assertSame($files, $this->compiled($cache));
        $this->assertSame($mtimes, array_map('filemtime', $this->compiled($cache)));
    }

    /** Renders in a fresh PHP process, like the next request (a compiled class stays loaded within one process). */
    private function renderInNewProcess(string $cache, string $template): string
    {
        $code = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . '$e = new Preflow\\Twig\\TwigEngine([' . var_export($this->templateDir, true) . '], '
            . 'new Preflow\\View\\AssetCollector(new Preflow\\View\\NonceGenerator()), cachePath: ' . var_export($cache, true) . ');'
            . 'echo $e->render(' . var_export($template, true) . ');';

        return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 -r ' . escapeshellarg($code));
    }

    public function test_an_edited_template_recompiles(): void
    {
        $cache = $this->templateDir . '/cache/twig';
        $this->createTemplate('page.twig', 'one');
        $abs = $this->templateDir . '/component.twig';
        file_put_contents($abs, 'compA');
        $this->assertSame('one', $this->renderInNewProcess($cache, 'page.twig'));
        $this->assertSame('compA', $this->renderInNewProcess($cache, $abs));

        file_put_contents($this->templateDir . '/page.twig', 'two');
        file_put_contents($abs, 'compB');
        touch($this->templateDir . '/page.twig', time() + 10);
        touch($abs, time() + 10);
        clearstatcache();

        $this->assertSame('two', $this->renderInNewProcess($cache, 'page.twig'));
        $this->assertSame('compB', $this->renderInNewProcess($cache, $abs));
    }

    public function test_output_is_identical_with_and_without_cache(): void
    {
        $this->createTemplate('base.twig', '<html>{% block body %}{% endblock %}</html>');
        $this->createTemplate('page.twig', '{% extends "base.twig" %}{% block body %}{% for i in 1..3 %}<p>{{ i }} {{ name|upper }}</p>{% endfor %}<script nonce="abc123"></script>{% endblock %}');
        $render = fn (TwigEngine $e): string => preg_replace('/nonce="[^"]*"/', 'nonce="X"', $e->render('page.twig', ['name' => 'x']));

        $plain = $render($this->makeEngine());
        $cache = $this->templateDir . '/cache/twig';
        $cold = $render($this->cachedEngine($cache));
        $warm = $render($this->cachedEngine($cache));

        $this->assertSame($plain, $cold);
        $this->assertSame($plain, $warm);
    }
}
