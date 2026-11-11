<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\View;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\View\FolioEngine;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\SharingTemplateEngine;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/** Folio's templates (*.twig) through a Twig engine of its own; the app's through the app's engine. */
final class FolioEngineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_engine_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/folio', 0777, true);
        @mkdir($this->dir . '/app', 0777, true);
        file_put_contents($this->dir . '/folio/page.twig', '<h1>{{ title }}</h1>{{ shout(title) }}');
        file_put_contents($this->dir . '/app/job.phtml', 'php:<?= $title ?>');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** A minimal non-Twig engine: .phtml files from app/, with the functions it was given. */
    private function phpEngine(): TemplateEngineInterface
    {
        return new class ($this->dir . '/app') implements TemplateEngineInterface {
            /** @var array<string, TemplateFunctionDefinition> */
            public array $functions = [];
            /** @var list<string> */
            public array $namespaces = [];

            public function __construct(private readonly string $dir) {}

            public function render(string $template, array $context = []): string
            {
                extract($context);
                ob_start();
                require $this->dir . '/' . $template;

                return (string) ob_get_clean();
            }

            public function exists(string $template): bool
            {
                return !str_starts_with($template, '@') && is_file($this->dir . '/' . $template);
            }

            public function addFunction(TemplateFunctionDefinition $function): void
            {
                $this->functions[$function->name] = $function;
            }

            public function addGlobal(string $name, mixed $value): void {}

            public function getTemplateExtension(): string
            {
                return 'phtml';
            }

            public function addNamespace(string $namespace, string $path): void
            {
                $this->namespaces[] = $namespace;
            }
        };
    }

    private function twig(): TwigEngine
    {
        return new TwigEngine([], new AssetCollector(new NonceGenerator()));
    }

    public function test_on_a_non_twig_app_twig_names_go_to_folio_s_own_engine_and_the_rest_to_the_app(): void
    {
        $php = $this->phpEngine();
        $app = new SharingTemplateEngine($php);
        $app->addFunction(new TemplateFunctionDefinition('shout', static fn (string $s): string => strtoupper($s)));
        $built = 0;
        $engine = FolioEngine::for($app, function () use (&$built): TemplateEngineInterface {
            $built++;

            return $this->twig();
        });
        $engine->addNamespace('folio', $this->dir . '/folio');
        // Folio's functions (folio_get, …) reach both engines.
        $engine->addFunction(new TemplateFunctionDefinition('folio_get', static fn (): string => 'site'));
        $this->assertArrayHasKey('folio_get', $php->functions);
        $this->assertSame(0, $built, 'Folio\'s engine is built on its first render');

        $this->assertTrue($engine->exists('@folio/page.twig'));
        $this->assertSame('<h1>Hi</h1>HI', $engine->render('@folio/page.twig', ['title' => 'Hi']), 'with the app\'s functions');
        $this->assertTrue($engine->exists('job.phtml'));
        $this->assertFalse($engine->exists('@folio/job.phtml'));
        $this->assertSame('php:Hi', $engine->render('job.phtml', ['title' => 'Hi']));
        $this->assertSame(1, $built);
        $this->assertSame([], $php->namespaces, 'Folio\'s namespaces are its own engine\'s');
        $this->assertSame('phtml', $engine->getTemplateExtension());

        file_put_contents($this->dir . '/folio/get.twig', '{{ folio_get() }}');
        $this->assertSame('site', $engine->render('@folio/get.twig'));
    }

    public function test_on_a_twig_app_both_are_the_app_s_engine(): void
    {
        $twig = $this->twig();
        $engine = FolioEngine::for($twig, static fn (): TemplateEngineInterface => throw new \LogicException('never built'));
        $engine->addNamespace('folio', $this->dir . '/folio');
        $engine->addFunction(new TemplateFunctionDefinition('shout', static fn (string $s): string => strtoupper($s)));

        $this->assertSame($twig, $engine->app());
        $this->assertSame($twig, $engine->twig());
        $this->assertSame('<h1>x</h1>X', $twig->render('@folio/page.twig', ['title' => 'x']), 'registered on the app\'s Twig, as before');
    }
}
