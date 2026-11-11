<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Core\Http\Hypermedia\StandIn;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The driver config/app.php 'hypermedia' names, built on its first use: core wires the hd.*
 * helpers, the component endpoint and the form helpers while it boots, before the service
 * providers run, and this stands in for the driver until then. So the driver's constructor can
 * take services a provider binds. Every call goes to the one driver, built once through the
 * container.
 */
class LazyHypermediaDriver implements HypermediaDriver, StandIn
{
    private ?HypermediaDriver $driver = null;

    /**
     * @param \Closure(): HypermediaDriver $resolve
     * @param class-string<HypermediaDriver> $class the driver's class (HypermediaProtocols tells clients apart by it)
     */
    public function __construct(
        private readonly \Closure $resolve,
        private readonly string $class = HypermediaDriver::class,
    ) {}

    /**
     * The driver itself (built now if it wasn't yet). A failure to build it is a RuntimeException
     * naming the class: "app.hypermedia: could not build X: …".
     */
    public function driver(): HypermediaDriver
    {
        if ($this->driver === null) {
            try {
                $this->driver = ($this->resolve)();
            } catch (\Throwable $e) {
                throw new \RuntimeException("app.hypermedia: could not build {$this->class}: {$e->getMessage()}", 0, $e);
            }
        }

        return $this->driver;
    }

    public function standsInFor(): string
    {
        return $this->class;
    }

    public function actionAttrs(string $method, string $url, string $targetId, SwapStrategy $swap, array $extra = []): HtmlAttributes
    {
        return $this->driver()->actionAttrs($method, $url, $targetId, $swap, $extra);
    }

    public function listenAttrs(string $event, string $url, string $targetId): HtmlAttributes
    {
        return $this->driver()->listenAttrs($event, $url, $targetId);
    }

    public function triggerEvent(string $event): void
    {
        $this->driver()->triggerEvent($event);
    }

    public function redirect(string $url): void
    {
        $this->driver()->redirect($url);
    }

    public function pushUrl(string $url): void
    {
        $this->driver()->pushUrl($url);
    }

    public function applyEffects(ResponseInterface $response): ResponseInterface
    {
        return $this->driver()->applyEffects($response);
    }

    public function assetTag(): string
    {
        return $this->driver()->assetTag();
    }

    public function isFragmentRequest(ServerRequestInterface $request): bool
    {
        return $this->driver()->isFragmentRequest($request);
    }

    public function isHistoryRestore(ServerRequestInterface $request): bool
    {
        return $this->driver()->isHistoryRestore($request);
    }

    public function fragmentTarget(ServerRequestInterface $request): ?string
    {
        return $this->driver()->fragmentTarget($request);
    }

    public function varyHeaders(): array
    {
        return $this->driver()->varyHeaders();
    }

    public function navigate(ResponseInterface $response, ?string $url): ResponseInterface
    {
        return $this->driver()->navigate($response, $url);
    }

    public function announceNonce(ResponseInterface $response, string $nonce): ResponseInterface
    {
        return $this->driver()->announceNonce($response, $nonce);
    }

    public function nonceHeaders(): array
    {
        return $this->driver()->nonceHeaders();
    }

    public function pageNonce(ServerRequestInterface $request): ?string
    {
        return $this->driver()->pageNonce($request);
    }
}
