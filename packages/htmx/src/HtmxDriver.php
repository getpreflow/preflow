<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Psr\Http\Message\ServerRequestInterface;

final class HtmxDriver implements HypermediaDriver
{
    public function __construct(
        private readonly ResponseHeaders $responseHeaders,
    ) {}

    public function actionAttrs(
        string $method,
        string $url,
        string $targetId,
        SwapStrategy $swap,
        array $extra = [],
    ): HtmlAttributes {
        return new HtmlAttributes(array_merge([
            "hx-{$method}" => $url,
            'hx-target' => "#{$targetId}",
            'hx-swap' => $swap->value,
        ], $extra));
    }

    public function listenAttrs(
        string $event,
        string $url,
        string $targetId,
    ): HtmlAttributes {
        return new HtmlAttributes([
            'hx-trigger' => "{$event} from:body",
            'hx-get' => $url,
            'hx-target' => "#{$targetId}",
            'hx-swap' => SwapStrategy::OuterHTML->value,
        ]);
    }

    public function triggerEvent(string $event): void
    {
        $this->responseHeaders->set('HX-Trigger', $event);
    }

    public function redirect(string $url): void
    {
        $this->responseHeaders->set('HX-Redirect', $url);
    }

    public function pushUrl(string $url): void
    {
        $this->responseHeaders->set('HX-Push-Url', $url);
    }

    public function isFragmentRequest(ServerRequestInterface $request): bool
    {
        return $request->hasHeader('HX-Request');
    }

    public function assetTag(): string
    {
        return '<script src="https://unpkg.com/htmx.org@2" defer></script>';
    }
}
