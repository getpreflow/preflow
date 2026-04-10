<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Psr\Http\Message\ServerRequestInterface;

interface HypermediaDriver
{
    public function actionAttrs(
        string $method,
        string $url,
        string $targetId,
        SwapStrategy $swap,
        array $extra = [],
    ): HtmlAttributes;

    public function listenAttrs(
        string $event,
        string $url,
        string $targetId,
    ): HtmlAttributes;

    public function triggerEvent(string $event): void;

    public function redirect(string $url): void;

    public function pushUrl(string $url): void;

    public function isFragmentRequest(ServerRequestInterface $request): bool;

    public function assetTag(): string;
}
