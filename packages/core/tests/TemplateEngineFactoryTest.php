<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/Pluggable/PhtmlEngine.php';
require_once __DIR__ . '/Fixtures/Pluggable/PhtmlEngineFactory.php';
require_once __DIR__ . '/Fixtures/Pluggable/PhtmlCounter/PhtmlCounter.php';
require_once __DIR__ . '/Fixtures/Pluggable/MemoryDriver.php';
require_once __DIR__ . '/Fixtures/Pluggable/SignalDriver.php';
require_once __DIR__ . '/Fixtures/Pluggable/Greeting.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\Pluggable\GreetingEngineFactory;
use Preflow\Core\Tests\Fixtures\Pluggable\GreetingProvider;
use Preflow\Core\Tests\Fixtures\Pluggable\PhtmlCounter\PhtmlCounter;
use Preflow\Core\Tests\Fixtures\Pluggable\PhtmlEngine;
use Preflow\Core\Tests\Fixtures\Pluggable\PhtmlEngineFactory;
use Preflow\View\LazyTemplateEngine;
use Preflow\View\SharingTemplateEngine;
use Preflow\View\TemplateEngineContext;
use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateEngineInterface;

/** app.engine may name a TemplateEngineFactory: its engine renders file pages and components. */
final class TemplateEngineFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/engine_factory_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function app(string $engine): Application
    {
        file_put_contents(
            $this->dir . '/config/app.php',
            "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>" . var_export($engine, true) . ",'key'=>'engine-factory-test-key-0123456789'];\n",
        );
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    #[RunInSeparateProcess]
    public function test_the_factorys_engine_renders_a_file_page_with_a_component(): void
    {
        file_put_contents($this->dir . '/app/pages/index.phtml', '<html><head></head><body><h1><?= $e($title ?? "Hello & welcome") ?></h1>'
            . '<?= $call("component", ' . var_export(PhtmlCounter::class, true) . ', ["count" => 2]) ?>'
            . '<script nonce="<?= $call("csp_nonce") ?>">window.x = 1;</script></body></html>');
        $app = $this->app(PhtmlEngineFactory::class);

        $sharing = $app->container()->get(TemplateEngineInterface::class);
        $this->assertInstanceOf(SharingTemplateEngine::class, $sharing);
        $engine = $sharing->engine();
        $this->assertInstanceOf(LazyTemplateEngine::class, $engine);
        $this->assertSame('phtml', $sharing->getTemplateExtension(), 'the extension without building the engine');
        $response = $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/'));
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), $html);
        $this->assertInstanceOf(PhtmlEngine::class, $engine->engine());
        $this->assertStringContainsString('<h1>Hello &amp; welcome</h1>', $html);
        $this->assertMatchesRegularExpression('#<div id="PhtmlCounter-[^"]+"><p class="count">Count: 2</p><button hx-post="/--component/action\?token=#', $html);
        preg_match('/<script nonce="([^"]+)">window.x/', $html, $nonce);
        $this->assertStringContainsString("'nonce-" . ($nonce[1] ?? 'none') . "'", $response->getHeaderLine('Content-Security-Policy'));
    }

    #[RunInSeparateProcess]
    public function test_the_factory_is_built_on_first_use_by_the_container_and_gets_what_the_built_ins_get(): void
    {
        $app = $this->app(PhtmlEngineFactory::class);
        $engine = $app->container()->get(TemplateEngineInterface::class)->engine();
        $this->assertNull(PhtmlEngineFactory::$context, 'not built during the boot');
        $engine->engine();
        $context = PhtmlEngineFactory::$context;
        $this->assertSame($context, $app->container()->get(TemplateEngineContext::class), 'the context is bound');

        $this->assertNotNull(PhtmlEngineFactory::$paths, 'constructor parameters are autowired');
        $this->assertNotNull($context);
        $this->assertSame([$this->dir . '/app/pages', $this->dir], $context->templateDirs);
        $this->assertSame($this->dir . '/storage/cache/templates', $context->cachePath);
        $this->assertFalse($context->debug);
        $this->assertNotNull($context->geo);
        $this->assertNotNull($context->liveParts);
        $this->assertNotNull($context->pageCache);
    }

    #[RunInSeparateProcess]
    public function test_another_engine_can_share_the_functions_and_globals_of_a_non_twig_app_engine(): void
    {
        $app = $this->app(PhtmlEngineFactory::class);
        $sharing = $app->container()->get(TemplateEngineInterface::class);
        $other = new PhtmlEngine([$this->dir . '/app/pages']);
        $sharing->share($other);
        $sharing->addFunction(new TemplateFunctionDefinition('later', static fn (): string => 'later', isSafe: true));
        file_put_contents($this->dir . '/app/pages/shared.phtml', '<?= $call("csp_nonce") ?>|<?= $call("later") ?>');

        $html = $other->render('shared.phtml');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]{16,}\|/', $html, 'a function core registered before');
        $this->assertStringEndsWith('|later', $html, 'and one registered after');
    }

    #[RunInSeparateProcess]
    public function test_a_twig_app_keeps_its_twig_engine_and_binds_its_context(): void
    {
        $app = $this->app('twig');

        $this->assertInstanceOf(\Preflow\Twig\TwigEngine::class, $app->container()->get(TemplateEngineInterface::class));
        $context = $app->container()->get(TemplateEngineContext::class);
        $this->assertStringStartsWith($this->dir . '/storage/cache/twig', $context->cachePath);
        $this->assertSame([$this->dir . '/app/pages', $this->dir], $context->templateDirs);
    }

    #[RunInSeparateProcess]
    public function test_the_factory_may_need_a_service_a_provider_binds(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(GreetingProvider::class, true) . "];\n");
        file_put_contents($this->dir . '/app/pages/index.phtml', '<html><head></head><body><p><?= $e($greeting) ?></p>'
            . '<?= $call("csp_nonce") !== "" ? "nonce" : "" ?></body></html>');
        $app = $this->app(GreetingEngineFactory::class);

        $html = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/'))->getBody();

        $this->assertStringContainsString('<p>hello from a provider</p>', $html);
        $this->assertStringContainsString('nonce', $html, 'functions registered before the engine was built reach it');
    }

    public function test_an_unknown_engine_throws_with_the_accepted_forms(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "Unknown template engine: php. Use 'twig', 'blade' or the class name of a Preflow\\View\\TemplateEngineFactory.",
        );

        $this->app('php');
    }

    public function test_a_class_that_is_no_factory_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Template engine ' . PhtmlEngine::class . ' (app.engine) does not implement Preflow\\View\\TemplateEngineFactory.');

        $this->app(PhtmlEngine::class);
    }
}
