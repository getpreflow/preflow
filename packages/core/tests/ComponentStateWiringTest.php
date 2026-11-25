<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/Pluggable/PhtmlEngine.php';
require_once __DIR__ . '/Fixtures/Pluggable/PhtmlEngineFactory.php';
require_once __DIR__ . '/Fixtures/State/StatefulTally/StatefulTally.php';
require_once __DIR__ . '/Fixtures/State/MemoryTally.php';
require_once __DIR__ . '/Fixtures/State/ShortTally/ShortTally.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\SignedStateStore;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\Pluggable\PhtmlEngineFactory;
use Preflow\Core\Tests\Fixtures\State\MemoryStateStore;
use Preflow\Core\Tests\Fixtures\State\MemoryTally;
use Preflow\Core\Tests\Fixtures\State\ShortTally\ShortTally;
use Preflow\Core\Tests\Fixtures\State\StatefulTally\StatefulTally;
use Preflow\Testing\ChecksFrameBalance;

/** The app's component renderer keeps #[State]: signed with the app key, one signed store per request, others from the container. */
final class ComponentStateWiringTest extends TestCase
{
    use ChecksFrameBalance;

    private const KEY = 'component-state-wiring-test-key-0123456789';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/component_state_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/app/pages/index.phtml', '<html><head></head><body>'
            . '<?= $call("component", ' . var_export(StatefulTally::class, true) . ', ["start" => 2]) ?></body></html>');
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn " . var_export(
            ['name' => 't', 'debug' => 0, 'engine' => PhtmlEngineFactory::class, 'key' => self::KEY],
            true,
        ) . ";\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    #[RunInSeparateProcess]
    public function test_state_is_signed_with_the_app_key(): void
    {
        $app = $this->app();
        $html = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/'))->getBody();
        $this->assertStringContainsString('<p>Tally 2</p>', $html, 'mounted from its props');

        $renderer = $app->container()->get(ComponentRenderer::class);
        $tally = new StatefulTally();
        $tally->setProps(['start' => 7]);
        $renderer->render($tally);

        $carried = $renderer->stateFor($tally);
        $this->assertNotNull($carried);
        $this->assertSame(['tally' => 7], (new SignedStateStore(self::KEY))->get(StatefulTally::class, $tally->getComponentId(), $carried));
    }

    #[RunInSeparateProcess]
    public function test_each_request_gets_its_own_signed_store(): void
    {
        $app = $this->app();
        $store = new \ReflectionProperty(Application::class, 'signedStateStore');

        $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/'));
        $first = $store->getValue($app);
        $this->assertInstanceOf(SignedStateStore::class, $first, 'made by the page with a stateful component');

        $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/'));
        $this->assertNotSame($first, $store->getValue($app), 'a long-lived store would issue state as old as its first request');
    }

    #[RunInSeparateProcess]
    public function test_a_store_named_by_state_in_comes_from_the_container(): void
    {
        $app = $this->app();
        $memory = new MemoryStateStore();
        $app->container()->instance(MemoryStateStore::class, $memory);

        $renderer = $app->container()->get(ComponentRenderer::class);
        $tally = new MemoryTally();
        $tally->setProps(['start' => 4]);
        $renderer->render($tally);

        $this->assertSame('m0', $renderer->stateFor($tally));
        $this->assertSame(['m0' => ['tally' => 4]], $memory->kept);
    }

    #[RunInSeparateProcess]
    public function test_the_page_carries_the_state_and_an_action_runs_on_it(): void
    {
        $app = $this->app();
        do { // two requests in one second: state is issued with the second it was rendered in
            $second = time();
            $page = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/'))->getBody();
            $again = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/'))->getBody();
        } while (time() !== $second);
        $body = static fn (string $html): string => (string) strstr($html, '</head>');
        $this->assertNotSame('', $body($page));
        $this->assertSame($body($page), $body($again), 'equal state, byte-equal component HTML (each response has its own nonce)');

        $tally = new StatefulTally();
        $tally->setProps(['start' => 2]);
        $id = $tally->getComponentId();
        $this->assertSame(1, preg_match('/name="_state\[' . preg_quote($id, '/') . '\]"[^>]*value="([^"]+)"/', $page, $m), $page);

        $token = (new \Preflow\Htmx\ComponentToken(self::KEY))->encodeFor($tally, 'bump');
        $response = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', 'http://localhost/--component/action')
            ->withParsedBody(['token' => $token, '_state' => [$id => html_entity_decode($m[1])]])
            ->withHeader('HX-Request', 'true'));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('<p>Tally 3</p>', (string) $response->getBody(), 'the action ran on the carried state');
        $this->assertStringContainsString('hx-swap-oob="true"', (string) $response->getBody(), 'the new state goes back');

        $refused = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', 'http://localhost/--component/action')
            ->withParsedBody(['token' => $token, '_state' => [$id => 'x' . html_entity_decode($m[1])]])
            ->withHeader('HX-Request', 'true'));
        $this->assertSame(403, $refused->getStatusCode(), 'tampered state answers like a refused token');
    }

    #[RunInSeparateProcess]
    public function test_the_short_call_runs_on_the_rendering_component(): void
    {
        file_put_contents($this->dir . '/app/pages/index.phtml', '<html><head></head><body>'
            . '<?= $call("component", ' . var_export(ShortTally::class, true) . ', ["start" => 4]) ?></body></html>');
        $page = (string) $this->app()->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/'))->getBody();

        $tally = new ShortTally();
        $tally->setProps(['start' => 4]);
        $id = $tally->getComponentId();
        $this->assertSame(1, preg_match('/<button hx-post="([^"]+)"[^>]*hx-vals="([^"]+)"/', $page, $post), $page);
        $this->assertSame(1, preg_match('/name="_state\[' . preg_quote($id, '/') . '\]"[^>]*value="([^"]+)"/', $page, $state), $page);
        $url = html_entity_decode($post[1]);
        $this->assertSame('/--component/action', $url, 'a post sends its token in the body');
        $token = (string) json_decode(html_entity_decode($post[2]), true)['_component_token'];
        $payload = (new \Preflow\Htmx\ComponentToken(self::KEY))->decode($token);
        $this->assertSame([ShortTally::class, 'bump', true, true], [$payload->componentClass, $payload->action, $payload->neutralCall, $payload->stateCarried]);

        $response = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', 'http://localhost' . $url)
            ->withParsedBody(['_component_token' => $token, '_state' => [$id => html_entity_decode($state[1])], '_values' => '{"by":1}', '_region' => ''])
            ->withHeader('HX-Request', 'true'));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('<p>Tally 5</p>', (string) $response->getBody(), 'the action ran on the carried state');
    }

    #[RunInSeparateProcess]
    public function test_the_short_on_refreshes_with_the_carried_state(): void
    {
        file_put_contents($this->dir . '/app/pages/index.phtml', '<html><head></head><body>'
            . '<?= $call("component", ' . var_export(ShortTally::class, true) . ', ["start" => 4]) ?></body></html>');
        $page = (string) $this->app()->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/'))->getBody();
        $tally = new ShortTally();
        $tally->setProps(['start' => 4]);
        $id = $tally->getComponentId();
        $this->assertSame(1, preg_match('/<button hx-post="([^"]+)"[^>]*hx-vals="([^"]+)"/', $page, $post), $page);
        $this->assertSame(1, preg_match('/<div hx-post="([^"]+)"[^>]*hx-vals="([^"]+)"[^>]*hx-trigger="tallied from:body"/', $page, $listen), $page);
        $this->assertSame('/--component/render', html_entity_decode($listen[1]), 'the token goes in the body');
        $token = static fn (array $call): string => (string) json_decode(html_entity_decode($call[2]), true)['_component_token'];
        $carried = static function (string $html) use ($id): string {
            preg_match('/name="_state\[' . preg_quote($id, '/') . '\]"[^>]*value="([^"]+)"/', $html, $m);

            return html_entity_decode($m[1] ?? '');
        };

        // The action bumps the tally to 5; the answer carries the new state.
        $bumped = (string) $this->app()->handle((new Psr17Factory())->createServerRequest('POST', 'http://localhost' . html_entity_decode($post[1]))
            ->withParsedBody(['_component_token' => $token($post), '_state' => [$id => $carried($page)], '_values' => '{}', '_region' => ''])
            ->withHeader('HX-Request', 'true'))->getBody();
        $this->assertStringContainsString('<p>Tally 5</p>', $bumped);

        // The event refresh with that state renders 5 again (mounting from the props would give 4).
        $response = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', 'http://localhost' . html_entity_decode($listen[1]))
            ->withParsedBody(['_component_token' => $token($listen), '_state' => [$id => $carried($bumped)], '_values' => '{}', '_region' => ''])
            ->withHeader('HX-Request', 'true'));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('<p>Tally 5</p>', (string) $response->getBody(), 'refreshed on the carried state, not mounted again');
    }
}
