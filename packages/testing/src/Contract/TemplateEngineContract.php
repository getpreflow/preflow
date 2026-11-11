<?php

declare(strict_types=1);

namespace Preflow\Testing\Contract;

use PHPUnit\Framework\TestCase;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * What every TemplateEngineInterface implementation does, as tests. Extend it, implement the two
 * hooks, and run it with PHPUnit:
 *
 *     final class MyEngineContractTest extends TemplateEngineContract
 *     {
 *         protected function createEngine(string $templateDir): TemplateEngineInterface
 *         {
 *             return new MyEngine([$templateDir]);
 *         }
 *
 *         protected function fixture(string $name): string
 *         {
 *             return match ($name) {
 *                 'variable' => '{{ name }}',
 *                 // …
 *             };
 *         }
 *     }
 *
 * Each test calls createEngine() with a fresh, still empty temporary directory, then writes the
 * fixtures into it as "{name}.{extension}" (the extension is that engine's
 * getTemplateExtension()), renders templates by that file name, and removes the directory
 * afterwards. So an engine must not list or compile its templates when it is created.
 *
 * fixture() returns the engine's source for these templates:
 *
 * - `variable`: prints the context variable `name`
 * - `escaped`: prints the context variable `html` with the engine's default escaping
 * - `raw_function`: prints the result of the registered function `shout` called with the
 *   string 'hi' (registered with isSafe true, so printed as it is)
 * - `escaped_function`: the same call (registered with isSafe false, so escaped); often the
 *   same source as `raw_function`
 * - `global`: prints the global `site`
 * - `namespaced`: includes the template `part` from the namespace `lib` (Twig:
 *   `{% include '@lib/part.twig' %}`, Blade: `@include('lib::part')`)
 * - `part`: prints the plain text `PART`
 *
 * Whitespace around the output does not matter: the tests trim it.
 */
abstract class TemplateEngineContract extends TestCase
{
    private const array FIXTURES = ['variable', 'escaped', 'raw_function', 'escaped_function', 'global', 'namespaced', 'part'];

    /** The engine under test, reading templates from $templateDir. */
    abstract protected function createEngine(string $templateDir): TemplateEngineInterface;

    /** The engine's source of the fixture template $name (see the class docblock). */
    abstract protected function fixture(string $name): string;

    private string $root;
    private string $templateDir;
    private TemplateEngineInterface $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/preflow_engine_contract_' . bin2hex(random_bytes(6));
        $this->templateDir = $this->root . '/templates';
        mkdir($this->templateDir, 0777, true);
        $this->engine = $this->createEngine($this->templateDir);
        foreach (self::FIXTURES as $name) {
            file_put_contents($this->templateDir . '/' . $this->file($name), $this->fixture($name));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            self::removeDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_renders_a_template_with_its_context(): void
    {
        $this->assertSame('Ada', $this->render('variable', ['name' => 'Ada']));
    }

    public function test_escapes_output_by_default(): void
    {
        $html = '<b class="x">Tom & Jerry</b>';

        $out = $this->render('escaped', ['html' => $html]);

        $this->assertStringNotContainsString('<b', $out, 'a variable is printed HTML-escaped unless marked safe');
        $this->assertSame($html, html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'the escaped output decodes to the value');
    }

    public function test_a_safe_function_prints_its_html_as_it_is(): void
    {
        $this->engine->addFunction(new TemplateFunctionDefinition('shout', self::shout(), isSafe: true));

        $this->assertSame('<b>HI</b>', $this->render('raw_function'));
    }

    public function test_a_function_that_is_not_safe_is_escaped(): void
    {
        $this->engine->addFunction(new TemplateFunctionDefinition('shout', self::shout(), isSafe: false));

        $out = $this->render('escaped_function');

        $this->assertStringNotContainsString('<b>', $out, 'a function registered with isSafe false is escaped');
        $this->assertSame('<b>HI</b>', html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function test_a_global_is_available_in_every_template(): void
    {
        $this->engine->addGlobal('site', 'Preflow');

        $this->assertSame('Preflow', $this->render('global'));
    }

    public function test_exists_tells_present_from_missing_templates(): void
    {
        $this->assertTrue($this->engine->exists($this->file('variable')), 'exists() is true for a template in the template directory');
        $this->assertFalse($this->engine->exists($this->file('no_such_template')), 'exists() is false for a missing template');
    }

    public function test_rendering_a_missing_template_throws(): void
    {
        $this->expectException(\Throwable::class);

        $this->engine->render($this->file('no_such_template'));
    }

    public function test_a_namespace_resolves_to_its_path(): void
    {
        $this->engine->addNamespace('lib', $this->namespaceDir('lib', $this->fixture('part')));

        $this->assertSame('PART', $this->render('namespaced'));
    }

    public function test_the_first_path_registered_for_a_namespace_wins(): void
    {
        $first = $this->namespaceDir('first', $this->fixture('part'));
        $second = $this->namespaceDir('second', 'SECOND');

        $this->engine->addNamespace('lib', $first);
        $this->engine->addNamespace('lib', $second);
        $this->assertSame('PART', $this->render('namespaced'), 'the path registered first is searched first');

        $other = $this->createEngine($this->templateDir);
        $other->addNamespace('lib', $second);
        $other->addNamespace('lib', $first);
        $this->assertSame('SECOND', trim($other->render($this->file('namespaced'))), 'the path registered first is searched first');
    }

    /** @param array<string, mixed> $context */
    private function render(string $name, array $context = []): string
    {
        return trim($this->engine->render($this->file($name), $context));
    }

    private function file(string $name): string
    {
        return $name . '.' . $this->engine->getTemplateExtension();
    }

    /** A directory under the test's root holding "part" with the given source. */
    private function namespaceDir(string $name, string $partSource): string
    {
        $dir = $this->root . '/' . $name;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/' . $this->file('part'), $partSource);

        return $dir;
    }

    /** The function `shout`: its argument upper-case in <b>. */
    private static function shout(): \Closure
    {
        return static fn (string $text): string => '<b>' . strtoupper($text) . '</b>';
    }

    private static function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
