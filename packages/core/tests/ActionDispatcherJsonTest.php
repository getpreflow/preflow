<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Psr\Http\Message\ResponseInterface;

final class ActionDispatcherJsonController
{
    public function array(): array
    {
        return ['ok' => true];
    }

    public function serializable(): \JsonSerializable
    {
        return new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['id' => 7];
            }
        };
    }

    public function html(): string
    {
        return '<p>hi</p>';
    }
}

final class ActionDispatcherJsonTest extends TestCase
{
    private string $cwd = '';
    private string $tmp = '';

    /** Application::create([...]) uses the working directory as its base path: keep its storage/ out of the repo. */
    protected function setUp(): void
    {
        $this->cwd = getcwd() ?: '.';
        $this->tmp = sys_get_temp_dir() . '/preflow_dispatchjson_' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        chdir($this->tmp);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        @rmdir($this->tmp);
    }

    private function dispatch(string $method): ResponseInterface
    {
        $app = Application::create(['app' => ['debug' => true]]);

        $ensure = new \ReflectionMethod($app, 'ensureActionDispatcher');
        $ensure->invoke($app);
        $prop = new \ReflectionProperty($app, 'actionDispatcher');
        $dispatcher = $prop->getValue($app);

        $route = new Route(RouteMode::Action, handler: ActionDispatcherJsonController::class . '@' . $method);

        return $dispatcher($route, (new Psr17Factory())->createServerRequest('GET', '/'));
    }

    public function test_controller_returning_array_answers_json(): void
    {
        $response = $this->dispatch('array');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function test_controller_returning_json_serializable_answers_json(): void
    {
        $response = $this->dispatch('serializable');

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"id":7}', (string) $response->getBody());
    }

    public function test_controller_returning_string_still_answers_html(): void
    {
        $response = $this->dispatch('html');

        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertSame('<p>hi</p>', (string) $response->getBody());
    }
}
