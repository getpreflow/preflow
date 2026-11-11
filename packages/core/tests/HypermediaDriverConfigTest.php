<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/Pluggable/PhtmlEngine.php';
require_once __DIR__ . '/Fixtures/Pluggable/PhtmlEngineFactory.php';
require_once __DIR__ . '/Fixtures/Pluggable/PhtmlCounter/PhtmlCounter.php';
require_once __DIR__ . '/Fixtures/Pluggable/SignalDriver.php';
require_once __DIR__ . '/Fixtures/Pluggable/SignalFormDriver.php';
require_once __DIR__ . '/Fixtures/Pluggable/MemoryDriver.php';
require_once __DIR__ . '/Fixtures/Pluggable/Greeting.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\Pluggable\GreetingProvider;
use Preflow\Core\Tests\Fixtures\Pluggable\GreetingSignalDriver;
use Preflow\Core\Tests\Fixtures\Pluggable\PhtmlCounter\PhtmlCounter;
use Preflow\Core\Tests\Fixtures\Pluggable\PhtmlEngineFactory;
use Preflow\Core\Tests\Fixtures\Pluggable\SignalDriver;
use Preflow\Core\Tests\Fixtures\Pluggable\SignalFormDriver;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\HypermediaDriver;
use Preflow\Htmx\LazyHypermediaDriver;
use Preflow\View\FormHypermediaDriver;
use Psr\Http\Message\ResponseInterface;

/** app.hypermedia names the app's HypermediaDriver; hd.*, the component endpoint and forms use it. */
final class HypermediaDriverConfigTest extends TestCase
{
    private const KEY = 'hypermedia-config-test-key-0123456789';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hypermedia_cfg_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/app/pages/index.phtml', '<html><head></head><body>'
            . '<?= $call("component", ' . var_export(PhtmlCounter::class, true) . ', ["count" => 2]) ?></body></html>');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function app(?string $hypermedia): Application
    {
        $config = ['name' => 't', 'debug' => 0, 'engine' => PhtmlEngineFactory::class, 'key' => self::KEY];
        if ($hypermedia !== null) {
            $config['hypermedia'] = $hypermedia;
        }
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn " . var_export($config, true) . ";\n");
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @param array<string, string> $headers */
    private function bump(Application $app, array $headers): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', 'http://localhost/--component/action')
            ->withParsedBody(['token' => (new ComponentToken(self::KEY))->encode(PhtmlCounter::class, ['count' => 2], 'bump')]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    #[RunInSeparateProcess]
    public function test_the_configured_driver_is_the_apps_one(): void
    {
        $app = $this->app(SignalDriver::class);
        $container = $app->container();

        $driver = $container->get(HypermediaDriver::class);
        $this->assertInstanceOf(LazyHypermediaDriver::class, $driver);
        $this->assertInstanceOf(SignalDriver::class, $driver->driver());
        $this->assertSame($driver->driver(), $container->get(SignalDriver::class), 'components that type-hint the class get the same one');
        $this->assertFalse($container->bound(HtmxDriver::class), 'no htmx driver beside it');
        $this->assertFalse($container->bound(FormHypermediaDriver::class), 'forms stay plain: the driver has no form attributes');
    }

    #[RunInSeparateProcess]
    public function test_hd_helpers_write_the_drivers_attributes_and_htmx_stays_out(): void
    {
        $html = (string) $this->app(SignalDriver::class)->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/'))->getBody();

        $this->assertStringContainsString('<button data-on:click="@post(&quot;/--component/action?token=', $html);
        $this->assertStringNotContainsString('hx-post', $html);
        $this->assertStringNotContainsString('htmx-config', $html, 'htmx is not inlined for another driver');
    }

    #[RunInSeparateProcess]
    public function test_the_endpoint_asks_the_driver_for_the_target_and_applies_its_effects(): void
    {
        $app = $this->app(SignalDriver::class);

        $fragment = $this->bump($app, ['Signal-Request' => 'true', 'Signal-Target' => 'somewhere-else']);
        $this->assertSame(200, $fragment->getStatusCode(), (string) $fragment->getBody());
        $this->assertStringStartsWith('<p class="count">Count: 3</p>', (string) $fragment->getBody(), 'a fragment for another target: no wrapper');
        $this->assertSame('event counted; push /count/3', $fragment->getHeaderLine('Signal-Effects'));
        $this->assertSame('', $fragment->getHeaderLine('HX-Trigger'));
    }

    #[RunInSeparateProcess]
    public function test_without_a_target_the_endpoint_renders_the_whole_component(): void
    {
        $whole = $this->bump($this->app(SignalDriver::class), ['Signal-Request' => 'true']);

        $this->assertMatchesRegularExpression('#^<div id="PhtmlCounter-[^"]+"><p class="count">Count: 3</p>#', (string) $whole->getBody());
    }

    #[RunInSeparateProcess]
    public function test_form_helpers_use_a_driver_with_form_attributes(): void
    {
        file_put_contents($this->dir . '/app/pages/form.phtml', '<html><head></head><body>'
            . '<?= $fn("form_begin", ["action" => "/save", "componentId" => "box"])->begin() ?></form></body></html>');
        $app = $this->app(SignalFormDriver::class);

        $this->assertSame($app->container()->get(HypermediaDriver::class), $app->container()->get(FormHypermediaDriver::class));
        $html = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/form'))->getBody();
        $this->assertStringContainsString('data-on:submit="@post(&quot;/save&quot;)"', $html);
    }

    #[RunInSeparateProcess]
    public function test_the_driver_may_need_a_service_a_provider_binds(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(GreetingProvider::class, true) . "];\n");
        $app = $this->app(GreetingSignalDriver::class);

        $html = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/'))->getBody();

        $this->assertStringContainsString('data-on:click="@post(', $html);
        $this->assertSame('hello from a provider', $app->container()->get(GreetingSignalDriver::class)->greeting->text());
    }

    #[RunInSeparateProcess]
    public function test_core_asks_the_driver_which_requests_are_fragments(): void
    {
        file_put_contents($this->dir . '/app/pages/kind.phtml', '<html><head></head><body><?= $request->isFragment ? "fragment" : "page" ?></body></html>');
        $app = $this->app(SignalDriver::class);
        $get = fn (array $headers): ResponseInterface => $app->handle(array_reduce(
            array_keys($headers),
            static fn ($request, string $name) => $request->withHeader($name, $headers[$name]),
            (new Psr17Factory())->createServerRequest('GET', 'http://localhost/kind'),
        ));

        $signal = $get(['Signal-Request' => 'true']);
        $this->assertStringContainsString('<body>fragment</body>', (string) $signal->getBody());
        $this->assertFalse($signal->hasHeader('HX-Nonce'), 'the driver announces no nonce');
        $htmx = $get(['HX-Request' => 'true']);
        $this->assertStringContainsString('<body>page</body>', (string) $htmx->getBody(), 'an htmx header means nothing to this app');
        $this->assertFalse($htmx->hasHeader('HX-Nonce'));
    }

    #[RunInSeparateProcess]
    public function test_a_fragment_request_is_answered_with_its_pages_nonce(): void
    {
        $response = $this->bump($this->app(SignalDriver::class), ['Signal-Request' => 'true', 'Signal-Nonce' => 'cGFnZS1ub25jZS0xMjM0NQ==']);

        $this->assertStringContainsString("'nonce-cGFnZS1ub25jZS0xMjM0NQ=='", $response->getHeaderLine('Content-Security-Policy'));
        $other = $this->bump($this->app(SignalDriver::class), ['Signal-Request' => 'true']);
        $this->assertStringNotContainsString('cGFnZS1ub25jZS0xMjM0NQ==', $other->getHeaderLine('Content-Security-Policy'));
    }

    #[RunInSeparateProcess]
    public function test_htmx_stays_the_default_with_its_headers(): void
    {
        $app = $this->app(null);
        $this->assertInstanceOf(HtmxDriver::class, $app->container()->get(HypermediaDriver::class));
        $this->assertSame($app->container()->get(HypermediaDriver::class), $app->container()->get(FormHypermediaDriver::class));

        $response = $this->bump($app, ['HX-Request' => 'true', 'HX-Target' => 'somewhere-else']);
        $this->assertStringStartsWith('<p class="count">Count: 3</p>', (string) $response->getBody());
        $this->assertSame('counted', $response->getHeaderLine('HX-Trigger'));
        $this->assertSame('/count/3', $response->getHeaderLine('HX-Push-Url'));
        $this->assertNotSame('', $response->getHeaderLine('HX-Nonce'));
    }

    #[RunInSeparateProcess]
    public function test_naming_the_htmx_driver_is_the_default(): void
    {
        $this->assertInstanceOf(HtmxDriver::class, $this->app(HtmxDriver::class)->container()->get(HypermediaDriver::class));
    }

    public function test_an_unknown_driver_throws_with_the_accepted_forms(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Unknown hypermedia driver: datastar. Set app.hypermedia to the class name of a Preflow\Htmx\HypermediaDriver (default: Preflow\Htmx\HtmxDriver).',
        );

        $this->app('datastar');
    }
}
