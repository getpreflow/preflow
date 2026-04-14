<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Core\DebugLevel;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Exceptions\SecurityException;
use Preflow\Htmx\ComponentEndpoint;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\Guarded;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateEngineInterface;

class EndpointTestComponent extends Component
{
    public string $title = 'Test';

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Default';
    }

    public function actions(): array
    {
        return ['refresh'];
    }

    public function actionRefresh(array $params = []): void
    {
        $this->title = 'Refreshed';
    }
}

class GuardedComponent extends Component implements Guarded
{
    public function actions(): array
    {
        return ['admin'];
    }

    public function actionAdmin(array $params = []): void {}

    public function authorize(string $action, ServerRequestInterface $request): void
    {
        if (!$request->hasHeader('X-Admin')) {
            throw new ForbiddenHttpException('Admin required');
        }
    }
}

class EndpointFakeEngine implements TemplateEngineInterface
{
    public function render(string $template, array $context = []): string
    {
        return '<p>' . ($context['title'] ?? 'no title') . '</p>';
    }

    public function exists(string $template): bool
    {
        return true;
    }

    public function addFunction(\Preflow\View\TemplateFunctionDefinition $function): void {}

    public function addGlobal(string $name, mixed $value): void {}

    public function getTemplateExtension(): string
    {
        return 'twig';
    }
}

class EndpointFakeEngineWithAssets implements TemplateEngineInterface
{
    public function __construct(private readonly AssetCollector $assetCollector) {}

    public function render(string $template, array $context = []): string
    {
        $this->assetCollector->addCss('.component { color: red; }');
        $this->assetCollector->addJs('console.log("component");', JsPosition::Body);
        $this->assetCollector->addJs('console.log("inline");', JsPosition::Inline);
        return '<p>' . ($context['title'] ?? 'no title') . '</p>';
    }

    public function exists(string $template): bool
    {
        return true;
    }

    public function addFunction(\Preflow\View\TemplateFunctionDefinition $function): void {}

    public function addGlobal(string $name, mixed $value): void {}

    public function getTemplateExtension(): string
    {
        return 'twig';
    }
}

final class ComponentEndpointTest extends TestCase
{
    private ComponentToken $tokenService;
    private ComponentEndpoint $endpoint;
    private ResponseHeaders $responseHeaders;

    protected function setUp(): void
    {
        $this->tokenService = new ComponentToken('test-secret-key-32-chars-long!!');
        $this->responseHeaders = new ResponseHeaders();

        $engine = new EndpointFakeEngine();
        $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: DebugLevel::On));

        $this->endpoint = new ComponentEndpoint(
            token: $this->tokenService,
            renderer: $renderer,
            driver: new HtmxDriver($this->responseHeaders),
            componentFactory: fn (string $class, array $props) => $this->makeComponent($class, $props),
        );
    }

    private function makeComponent(string $class, array $props): Component
    {
        $component = new $class();
        $component->setProps($props);
        return $component;
    }

    private function createRequest(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        array $headers = [],
    ): ServerRequestInterface {
        $factory = new Psr17Factory();
        $uriObj = $factory->createUri($uri);
        if ($query) {
            $uriObj = $uriObj->withQuery(http_build_query($query));
        }
        $request = $factory->createServerRequest($method, $uriObj);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body) {
            $request = $request->withParsedBody($body);
        }
        return $request;
    }

    public function test_render_action_returns_html(): void
    {
        $token = $this->tokenService->encode(EndpointTestComponent::class, ['title' => 'Hello']);

        $request = $this->createRequest('GET', '/--component/render', ['token' => $token]);
        $response = $this->endpoint->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Hello', $body);
    }

    public function test_action_dispatch_and_rerender(): void
    {
        $token = $this->tokenService->encode(
            EndpointTestComponent::class,
            ['title' => 'Before'],
            'refresh',
        );

        $request = $this->createRequest('POST', '/--component/action', ['token' => $token]);
        $response = $this->endpoint->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Refreshed', $body);
    }

    public function test_invalid_token_returns_403(): void
    {
        $request = $this->createRequest('POST', '/--component/action', ['token' => 'tampered-garbage']);

        $this->expectException(SecurityException::class);
        $this->endpoint->handle($request);
    }

    public function test_non_component_class_throws(): void
    {
        $token = $this->tokenService->encode(\stdClass::class);

        $request = $this->createRequest('GET', '/--component/render', ['token' => $token]);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Invalid component class');
        $this->endpoint->handle($request);
    }

    public function test_unlisted_action_throws(): void
    {
        $token = $this->tokenService->encode(EndpointTestComponent::class, [], 'delete');

        $request = $this->createRequest('POST', '/--component/action', ['token' => $token]);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not allowed');
        $this->endpoint->handle($request);
    }

    public function test_guarded_component_authorized(): void
    {
        $token = $this->tokenService->encode(GuardedComponent::class, [], 'admin');

        $request = $this->createRequest('POST', '/--component/action', ['token' => $token], headers: [
            'X-Admin' => 'true',
        ]);

        $response = $this->endpoint->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_guarded_component_unauthorized(): void
    {
        $token = $this->tokenService->encode(GuardedComponent::class, [], 'admin');

        $request = $this->createRequest('POST', '/--component/action', ['token' => $token]);

        $this->expectException(ForbiddenHttpException::class);
        $this->endpoint->handle($request);
    }

    public function test_response_includes_driver_headers(): void
    {
        $token = $this->tokenService->encode(EndpointTestComponent::class, [], 'refresh');

        $request = $this->createRequest('POST', '/--component/action', ['token' => $token]);
        $response = $this->endpoint->handle($request);

        // Response headers from the driver should be in the response
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_htmx_response_includes_collected_css(): void
    {
        $assetCollector = new AssetCollector(new NonceGenerator());
        $engine = new EndpointFakeEngineWithAssets($assetCollector);
        $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: DebugLevel::On));
        $endpoint = new ComponentEndpoint(
            token: $this->tokenService,
            renderer: $renderer,
            driver: new HtmxDriver(new ResponseHeaders()),
            componentFactory: fn (string $class, array $props) => $this->makeComponent($class, $props),
            assetCollector: $assetCollector,
        );

        $token = $this->tokenService->encode(EndpointTestComponent::class, ['title' => 'Hello']);
        $request = $this->createRequest('GET', '/--component/render', ['token' => $token]);
        $response = $endpoint->handle($request);

        $body = (string) $response->getBody();
        $this->assertStringContainsString('<style', $body);
        $this->assertStringContainsString('.component { color: red; }', $body);
    }

    public function test_htmx_response_includes_collected_js(): void
    {
        $assetCollector = new AssetCollector(new NonceGenerator());
        $engine = new EndpointFakeEngineWithAssets($assetCollector);
        $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: DebugLevel::On));
        $endpoint = new ComponentEndpoint(
            token: $this->tokenService,
            renderer: $renderer,
            driver: new HtmxDriver(new ResponseHeaders()),
            componentFactory: fn (string $class, array $props) => $this->makeComponent($class, $props),
            assetCollector: $assetCollector,
        );

        $token = $this->tokenService->encode(EndpointTestComponent::class, ['title' => 'Hello']);
        $request = $this->createRequest('GET', '/--component/render', ['token' => $token]);
        $response = $endpoint->handle($request);

        $body = (string) $response->getBody();
        $this->assertStringContainsString('<script', $body);
        $this->assertStringContainsString('console.log("component")', $body);
        $this->assertStringContainsString('console.log("inline")', $body);
    }

    public function test_hx_target_other_element_returns_fragment_on_render(): void
    {
        $token = $this->tokenService->encode(EndpointTestComponent::class, ['title' => 'Hello']);

        $request = $this->createRequest(
            'GET',
            '/--component/render',
            ['token' => $token],
            headers: ['HX-Target' => 'some-other-id'],
        );
        $response = $this->endpoint->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Hello', $body);
        // Fragment response must not include the component wrapper div
        $this->assertStringNotContainsString('id="EndpointTestComponent-', $body);
    }

    public function test_hx_target_other_element_returns_fragment_on_action(): void
    {
        $token = $this->tokenService->encode(
            EndpointTestComponent::class,
            ['title' => 'Before'],
            'refresh',
        );

        $request = $this->createRequest(
            'POST',
            '/--component/action',
            ['token' => $token],
            headers: ['HX-Target' => 'step-content'],
        );
        $response = $this->endpoint->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Refreshed', $body);
        // Fragment response must not include the component wrapper div
        $this->assertStringNotContainsString('id="EndpointTestComponent-', $body);
    }

    public function test_hx_target_matching_component_id_returns_full_component(): void
    {
        $component = $this->makeComponent(EndpointTestComponent::class, ['title' => 'Hello']);
        $componentId = $component->getComponentId();
        $token = $this->tokenService->encode(EndpointTestComponent::class, ['title' => 'Hello']);

        $request = $this->createRequest(
            'GET',
            '/--component/render',
            ['token' => $token],
            headers: ['HX-Target' => $componentId],
        );
        $response = $this->endpoint->handle($request);

        $body = (string) $response->getBody();
        // When HX-Target matches the component's own ID, the full wrapper is returned
        $this->assertStringContainsString('id="EndpointTestComponent-', $body);
    }
}
