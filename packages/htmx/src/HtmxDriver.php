<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Core\Http\Hypermedia\CarriesRequestHeaders;
use Preflow\Core\Http\Hypermedia\OwnsResponseHeaders;
use Preflow\View\FormHypermediaDriver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HtmxDriver implements HypermediaDriver, FormHypermediaDriver, OwnsResponseHeaders, CarriesRequestHeaders
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

    /**
     * hx-headers on the page's <body> element, which every htmx request from the page inherits.
     * The first `<body` outside comments, scripts, styles, templates and textareas is it, and its
     * quoted attributes may hold a `>`. A JSON hx-headers the body has already keeps its other
     * headers. The body also gets hx-history="false" (replacing the page's own), so htmx keeps the
     * page out of its history cache. Where the page leaves it unsure (no <body>, an hx-headers that is not quoted plain
     * JSON: a js: value, an unquoted one) nothing changes: the header is then simply not carried
     * (component URLs still carry the token), never doubled or broken.
     *
     * @since 0.81.0
     */
    public function carryHeaders(string $html, array $headers): string
    {
        if ($headers === []) {
            return $html;
        }
        // Comments and raw-text elements blanked out (same length, so offsets hold).
        $masked = preg_replace_callback(
            '#<!--.*?-->|<(script|style|template|textarea)\b[^>]*>.*?</\1\s*>#is',
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $html,
        ) ?? '';
        if (preg_match('/<body\b/i', $masked, $start, PREG_OFFSET_CAPTURE) !== 1
            || preg_match('/\G<body\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $html, $tag, PREG_OFFSET_CAPTURE, $start[0][1]) !== 1) {
            return $html;
        }
        [$open, $at] = $tag[0];
        $quoted = preg_match('/\shx-headers\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $open, $existing) === 1;
        if (!$quoted && preg_match('/\shx-headers\b/i', $open) === 1) {
            return $html;
        }
        if ($quoted) {
            $own = json_decode(html_entity_decode(($existing[2] ?? '') !== '' ? $existing[2] : $existing[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!is_array($own) || ($own !== [] && array_is_list($own))) {
                return $html;
            }
            $open = str_replace($existing[0], '', $open);
            $headers += $own;
        }
        $json = json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        // Kept out of htmx's history cache (localStorage): what the page carries (a preview token, a
        // draft) never outlives it there. A value the page set itself is replaced.
        $open = preg_replace('/\s(?:data-)?hx-history\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $open) ?? $open;
        $attribute = ' hx-headers="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '" hx-history="false"';
        $open = preg_replace('#\s*/?>$#', $attribute . '>', $open, 1) ?? $open;

        return substr_replace($html, $open, $at, strlen($tag[0][0]));
    }

    /** Every HX-* response header: htmx reads them (HX-Trigger, HX-Redirect, HX-Push-Url, HX-Retarget…). */
    public function ownedHeaders(): array
    {
        return ['HX-*'];
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
