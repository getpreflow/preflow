<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Preflow\Core\Container\Container;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateEngineInterface;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Components\SignedStateStore;
use Preflow\Components\StateStore;
use Preflow\Core\DebugLevel;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\ResponseHeaders;

/**
 * A container with a component renderer, assets and htmx's token and response headers, for
 * component tests without a booted app. The ResponseHeaders follow the render frames (since
 * 0.81, as the app's do): inside Contexts::run() a component's HX-* headers are that frame's,
 * outside every render they are the object's own.
 */
final class TestApplication
{
    public readonly Container $container;
    public readonly AssetCollector $assets;
    public readonly ComponentRenderer $renderer;
    public readonly ComponentToken $token;
    public readonly ResponseHeaders $responseHeaders;

    public function __construct(
        DebugLevel $debug = DebugLevel::On,
        string $secretKey = 'test-secret-key-for-preflow-tests!',
    ) {
        $this->container = new Container();

        $nonce = new NonceGenerator();
        $this->assets = new AssetCollector($nonce);
        $this->responseHeaders = new ResponseHeaders();
        $this->token = new ComponentToken($secretKey);

        $errorBoundary = new ErrorBoundary(debug: $debug);
        $this->renderer = new ComponentRenderer(
            templateEngine: new class implements TemplateEngineInterface {
                public function render(string $template, array $context = []): string
                {
                    // Simple stub — real tests can override
                    return implode('', array_map(fn ($v) => is_string($v) ? $v : '', $context));
                }
                public function exists(string $template): bool { return true; }
                public function addFunction(\Preflow\View\TemplateFunctionDefinition $function): void {}
                public function addGlobal(string $name, mixed $value): void {}
                public function getTemplateExtension(): string { return 'twig'; }
                public function addNamespace(string $namespace, string $path): void {}
            },
            errorBoundary: $errorBoundary,
        );

        // Components with #[State] keep it in a signed store, as the app's renderer does
        // (Application::enableState: the signed store with the app key).
        $signed = new SignedStateStore($secretKey);
        $this->renderer->enableState(function (string $store) use ($signed): StateStore {
            if ($store !== SignedStateStore::class) {
                throw new \LogicException(sprintf('TestApplication keeps state in the signed store only; %s needs a booted app.', $store));
            }

            return $signed;
        }, $debug->isDebug());

        // Register in container
        $this->container->instance(AssetCollector::class, $this->assets);
        $this->container->instance(ComponentRenderer::class, $this->renderer);
        $this->container->instance(ComponentToken::class, $this->token);
        $this->container->instance(ResponseHeaders::class, $this->responseHeaders);
    }
}
