<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Core\Exceptions\SecurityException;
use Preflow\View\AssetCollector;

final class ComponentEndpoint
{
    /** @var callable(string, array): Component */
    private $componentFactory;

    /**
     * @param callable(string $class, array $props): Component $componentFactory
     */
    public function __construct(
        private readonly ComponentToken $token,
        private readonly ComponentRenderer $renderer,
        private readonly HypermediaDriver $driver,
        callable $componentFactory,
        private readonly ?AssetCollector $assetCollector = null,
    ) {
        $this->componentFactory = $componentFactory;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Extract token from query or body
        $queryParams = $request->getQueryParams();
        $bodyParams = $request->getParsedBody() ?? [];
        $tokenString = $queryParams['token'] ?? $bodyParams['token'] ?? null;

        if ($tokenString === null) {
            throw new SecurityException('Missing component token.');
        }

        // Layer 1: Token signature verification
        $payload = $this->token->decode($tokenString, maxAge: 86400);

        // Layer 2: Class validation
        if (!class_exists($payload->componentClass)
            || !is_subclass_of($payload->componentClass, Component::class)) {
            throw new SecurityException(
                "Invalid component class [{$payload->componentClass}]."
            );
        }

        // Create component via factory (supports DI)
        $component = ($this->componentFactory)($payload->componentClass, $payload->props);

        // Layer 3: Action whitelist
        if ($payload->action !== 'render'
            && !in_array($payload->action, $component->actions(), true)) {
            throw new SecurityException(
                "Action [{$payload->action}] is not allowed on [{$payload->componentClass}]."
            );
        }

        // Layer 4: Component-level guards
        if ($component instanceof Guarded && $payload->action !== 'render') {
            $component->authorize($payload->action, $request);
        }

        // Determine if HTMX is targeting a different element (partial swap)
        $hxTarget = $request->getHeaderLine('HX-Target');
        $isFragmentRequest = $hxTarget !== ''
            && $hxTarget !== $component->getComponentId();

        // Dispatch action or resolve initial state
        if ($payload->action !== 'render') {
            // Resolve state first so the action operates on correct base state
            $component->resolveState();
            $params = is_array($bodyParams) ? $bodyParams : [];
            $component->handleAction($payload->action, $params);
            // Render without re-calling resolveState so action side-effects are preserved
            $html = $isFragmentRequest
                ? $this->renderer->renderResolvedFragment($component)
                : $this->renderer->renderResolved($component);
        } else {
            // Plain render — resolveState is called inside renderer
            $html = $isFragmentRequest
                ? $this->renderer->renderFragment($component)
                : $this->renderer->render($component);
        }

        // Append any CSS/JS collected during the component render
        if ($this->assetCollector !== null) {
            $fragmentAssets = '';
            if ($this->assetCollector->hasCss()) {
                $fragmentAssets .= $this->assetCollector->renderCss();
            }
            if ($this->assetCollector->hasJs()) {
                $fragmentAssets .= $this->assetCollector->renderJsBody();
                $fragmentAssets .= $this->assetCollector->renderJsInline();
            }
            if ($fragmentAssets !== '') {
                $html .= $fragmentAssets;
            }
        }

        // Build response with driver headers
        $response = new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);

        if ($this->driver instanceof HtmxDriver) {
            $headers = $this->getDriverHeaders();
            foreach ($headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function getDriverHeaders(): array
    {
        // Access response headers via reflection or direct access
        // The ResponseHeaders object is shared with the driver
        $ref = new \ReflectionClass($this->driver);
        $prop = $ref->getProperty('responseHeaders');
        $headers = $prop->getValue($this->driver);

        return $headers instanceof ResponseHeaders ? $headers->all() : [];
    }
}
