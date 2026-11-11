<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Pluggable;

use Preflow\Htmx\HtmlAttributes;
use Preflow\Htmx\HypermediaDriver;
use Preflow\Htmx\SwapStrategy;
use Preflow\View\AssetCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** A non-htmx driver (Datastar-style data-on:* attributes), named as app.hypermedia. */
class SignalDriver implements HypermediaDriver
{
    /** @var list<string> */
    private array $effects = [];

    public function __construct(public readonly AssetCollector $assets) {}

    public function actionAttrs(string $method, string $url, string $targetId, SwapStrategy $swap, array $extra = []): HtmlAttributes
    {
        return new HtmlAttributes([
            'data-on:click' => '@' . $method . '(' . json_encode($url, JSON_UNESCAPED_SLASHES) . ', ' . json_encode(['target' => $targetId]) . ')',
            ...$extra,
        ]);
    }

    public function listenAttrs(string $event, string $url, string $targetId): HtmlAttributes
    {
        return new HtmlAttributes([
            "data-on:{$event}__window" => '@get(' . json_encode($url, JSON_UNESCAPED_SLASHES) . ', ' . json_encode(['target' => $targetId]) . ')',
        ]);
    }

    public function triggerEvent(string $event): void
    {
        $this->effects[] = "event {$event}";
    }

    public function redirect(string $url): void
    {
        $this->effects[] = "redirect {$url}";
    }

    public function pushUrl(string $url): void
    {
        $this->effects[] = "push {$url}";
    }

    public function isFragmentRequest(ServerRequestInterface $request): bool
    {
        return $request->hasHeader('Signal-Request');
    }

    public function fragmentTarget(ServerRequestInterface $request): ?string
    {
        $target = $request->getHeaderLine('Signal-Target');

        return $target === '' ? null : $target;
    }

    public function applyEffects(ResponseInterface $response): ResponseInterface
    {
        return $this->effects === [] ? $response : $response->withHeader('Signal-Effects', implode('; ', $this->effects));
    }

    public function assetTag(): string
    {
        return '<script type="module" src="/signal.js"></script>';
    }

    public function isHistoryRestore(ServerRequestInterface $request): bool
    {
        return false;
    }

    public function varyHeaders(): array
    {
        return ['Signal-Request', 'Signal-Target'];
    }

    public function navigate(ResponseInterface $response, ?string $url): ResponseInterface
    {
        return $response->withHeader('Signal-Location', $url ?? 'reload');
    }

    public function announceNonce(ResponseInterface $response, string $nonce): ResponseInterface
    {
        return $response;
    }

    public function nonceHeaders(): array
    {
        return [];
    }

    public function pageNonce(ServerRequestInterface $request): ?string
    {
        return $request->getHeaderLine('Signal-Nonce') ?: null;
    }

}
