<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\View\FormHypermediaDriver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HtmxDriver implements HypermediaDriver, FormHypermediaDriver
{
    private ?HtmxAssets $assets = null;

    /** Lets attribute helpers mark htmx as needed on the page (include mode "on-demand"). */
    public function useAssets(HtmxAssets $assets): void
    {
        $this->assets = $assets;
    }

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
        $this->assets?->require();
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
        $this->assets?->require();
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

    /** htmx marks its requests with HX-Request: true (a history restore too). */
    public function isFragmentRequest(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('HX-Request') === 'true';
    }

    /** htmx asks for the whole page again with HX-History-Restore-Request: true. */
    public function isHistoryRestore(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('HX-History-Restore-Request') === 'true';
    }

    /** HX-History-Restore-Request is never on a keyed request (a restore drops these): listed so a response may vary on it. */
    public function varyHeaders(): array
    {
        return ['HX-Request', 'HX-Target', 'HX-History-Restore-Request'];
    }

    /** HX-Redirect: htmx loads the URL as a page; HX-Refresh: it reloads the page. */
    public function navigate(ResponseInterface $response, ?string $url): ResponseInterface
    {
        return $url === null ? $response->withHeader('HX-Refresh', 'true') : $response->withHeader('HX-Redirect', $url);
    }

    /** HX-Nonce, unless the response has one: the page's nonce guard keeps only scripts with it (see HtmxAssets). */
    public function announceNonce(ResponseInterface $response, string $nonce): ResponseInterface
    {
        return $nonce === '' || $response->hasHeader('HX-Nonce') ? $response : $response->withHeader('HX-Nonce', $nonce);
    }

    public function nonceHeaders(): array
    {
        return ['HX-Nonce'];
    }

    /** htmx re-stamps the scripts it inserts with the page's nonce itself (inlineScriptNonce): none comes back. */
    public function pageNonce(ServerRequestInterface $request): ?string
    {
        return null;
    }

    /** htmx sends the target element's id in HX-Target. */
    public function fragmentTarget(ServerRequestInterface $request): ?string
    {
        $target = $request->getHeaderLine('HX-Target');

        return $target === '' ? null : $target;
    }

    /**
     * The recorded HX-* headers, plus HX-Nonce: the nonce this response's own <script>/<style>
     * carry, which the page's nonce guard lets through (see HtmxAssets).
     */
    public function applyEffects(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->responseHeaders->all() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $nonce = $this->assets?->nonce();

        return $nonce === null ? $response : $this->announceNonce($response, $nonce);
    }

    /**
     * @deprecated htmx is self-hosted and inlined by HtmxAssets; this returns an empty string.
     */
    public function assetTag(): string
    {
        return '';
    }

    public function formAttributes(string $action, string $method, array $options = []): array
    {
        $this->assets?->require();
        return [
            "hx-{$method}" => $action,
            'hx-target' => $options['target'] ?? 'this',
            'hx-swap' => $options['swap'] ?? 'outerHTML',
        ];
    }

    public function inlineValidationAttributes(string $endpoint, string $field, string $trigger = 'blur'): array
    {
        $this->assets?->require();
        return [
            'hx-post' => $endpoint,
            'hx-trigger' => $trigger,
            'hx-target' => 'closest .form-group',
            'hx-swap' => 'outerHTML',
            'hx-include' => "[name=\"{$field}\"]",
        ];
    }

    public function submitAttributes(string $target, array $options = []): array
    {
        $this->assets?->require();
        return [
            'hx-target' => $target,
            'hx-swap' => $options['swap'] ?? 'outerHTML',
        ];
    }
}
