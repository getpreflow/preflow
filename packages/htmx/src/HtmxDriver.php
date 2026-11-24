<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Core\Http\Hypermedia\ActionCall;
use Preflow\Core\Http\Hypermedia\CarriesRequestHeaders;
use Preflow\Core\Http\Hypermedia\DrivesComponentActions;
use Preflow\Core\Http\Hypermedia\OwnsResponseHeaders;
use Preflow\View\FormHypermediaDriver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HtmxDriver implements HypermediaDriver, FormHypermediaDriver, OwnsResponseHeaders, CarriesRequestHeaders, DrivesComponentActions
{
    /** The escape hatch's key in action options: htmx attributes passed through as they are. */
    public const string OPTIONS_KEY = 'htmx';

    /** The neutral swaps (ActionOptions::SWAPS) as hx-swap styles. */
    private const array SWAPS = [
        'outer' => 'outerHTML', 'inner' => 'innerHTML', 'before' => 'beforebegin', 'after' => 'afterend',
        'prepend' => 'afterbegin', 'append' => 'beforeend', 'delete' => 'delete', 'none' => 'none',
    ];

    /** The neutral events (ActionOptions::EVENTS) as hx-trigger events. */
    private const array EVENTS = [
        'click' => 'click', 'submit' => 'submit', 'change' => 'change', 'input' => 'input', 'load' => 'load', 'visible' => 'revealed',
    ];

    /**
     * The form attribute of a state carrier: an id no form has, so the carrier belongs to no form
     * of the page (a form around the component never posts it on its own).
     */
    private const string NO_FORM = 'preflow-no-form';

    private ?HtmxAssets $assets = null;

    /** Lets attribute helpers mark htmx as needed on the page (include mode "on-demand"). */
    public function useAssets(HtmxAssets $assets): void
    {
        $this->assets = $assets;
    }

    public function __construct(
        private readonly ResponseHeaders $responseHeaders,
    ) {}

    /**
     * hx-get/hx-post, hx-target, hx-swap (with `show:…:top` for scroll), hx-trigger (a debounce
     * delays typing only), hx-include (the state carrier first, then the regions and fields
     * named), hx-sync (one request per component at a time: `queue all` on the component, or
     * `this:replace` with `latest`), hx-disabled-elt, and hx-vals with the values (VALUES_FIELD)
     * and the region (REGION_FIELD). Attributes under the `htmx` key are added last, as given.
     *
     * @since 0.86.0
     */
    public function callAttributes(ActionCall $call): array
    {
        $this->assets?->require();
        $options = $call->options;
        $component = '#' . $call->componentId;

        $swap = self::SWAPS[$options->swap];
        if ($options->scroll === 'top') {
            $swap .= ' show:#' . $call->targetId . ':top';
        }
        $include = [$component . '--state'];
        foreach ($options->include as $name) {
            $include[] = $component . '-' . $name; // a region: its fields
            $include[] = $component . ' [name="' . $name . '"]'; // a field of the component
        }
        $attributes = [
            "hx-{$call->method}" => $call->url,
            'hx-target' => '#' . $call->targetId,
            'hx-swap' => $swap,
            'hx-include' => implode(', ', $include),
            'hx-sync' => $options->latest ? 'this:replace' : $component . ':queue all',
        ];
        if ($options->on !== []) {
            $attributes['hx-trigger'] = implode(', ', array_map(
                static fn (string $on): string => self::EVENTS[$on] . ($on === 'input' && $options->debounce !== null ? ' delay:' . $options->debounce . 'ms' : ''),
                $options->on,
            ));
        }
        if ($options->disable !== null) {
            $attributes['hx-disabled-elt'] = $options->disable === 'self' ? 'this' : $component . '-' . $options->disable;
        }
        $vals = self::fields(self::VALUES_FIELD, $call->values);
        if ($options->region() !== null) {
            $vals[self::REGION_FIELD] = $options->region();
        }
        if ($vals !== []) {
            $attributes['hx-vals'] = json_encode($vals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        foreach ($options->driverOptions(self::OPTIONS_KEY) as $name => $value) {
            $attributes[$name] = is_scalar($value) || $value instanceof \Stringable
                ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $attributes;
    }

    /**
     * A hidden input `{componentId}--state` named STATE_FIELD[componentId], outside every form (its
     * form attribute names none), which callAttributes() includes. The `--` never clashes with a
     * region's id (`{componentId}-{name}`, a name starts with a letter).
     *
     * @since 0.86.0
     */
    public function stateCarrier(string $componentId, string $carried): string
    {
        return '<input type="hidden" id="' . self::escape($componentId . '--state') . '" name="'
            . self::escape(self::STATE_FIELD . '[' . $componentId . ']') . '" form="' . self::NO_FORM
            . '" value="' . self::escape($carried) . '">';
    }

    /**
     * The carrier again, swapped out of band (hx-swap-oob): htmx replaces the page's carrier with
     * it, whatever the answer's own target.
     *
     * @since 0.86.0
     */
    public function stateUpdate(string $componentId, string $carried): string
    {
        return substr($this->stateCarrier($componentId, $carried), 0, -1) . ' hx-swap-oob="true">';
    }

    /**
     * $values as form fields under $prefix: `_values[a]`, `_values[rows][0][name]`… (PHP reads
     * them back as the same arrays). true is "1", false and null are "".
     *
     * @param array<array-key, mixed> $values
     * @return array<string, string|int|float>
     */
    private static function fields(string $prefix, array $values): array
    {
        $fields = [];
        foreach ($values as $name => $value) {
            $field = $prefix . '[' . $name . ']';
            if (is_array($value)) {
                $fields += self::fields($field, $value);
            } elseif (is_int($value) || is_float($value) || is_string($value)) {
                $fields[$field] = $value;
            } elseif (is_bool($value) || $value === null) {
                $fields[$field] = $value === true ? '1' : '';
            } elseif ($value instanceof \Stringable) {
                $fields[$field] = (string) $value;
            } elseif ($value instanceof \BackedEnum) {
                $fields[$field] = $value->value;
            } else {
                throw new \InvalidArgumentException(sprintf('The action value %s is a %s: values are text, numbers, true/false, null, backed enums or lists of them.', $field, get_debug_type($value)));
            }
        }

        return $fields;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

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
     * headers. The body always gets hx-history="false" (replacing the page's own), so htmx keeps the
     * page out of its history cache. An hx-headers of the page's own that is not quoted plain JSON
     * (a js: value, an unquoted one) is left as it is and the headers are not carried there
     * (component URLs still carry the token), never doubled or broken. A whole document without a
     * <body> tag gets one; a fragment (no <html>) is left alone.
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
            return self::withBody($html, $masked, $headers);
        }
        [$open, $at] = $tag[0];
        $quoted = preg_match('/\shx-headers\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $open, $existing) === 1;
        // An hx-headers of the page's own that is not quoted plain JSON (a js: value, an unquoted
        // one) is left as it is: the headers are not carried there, but the page still stays out of
        // the history cache.
        $carry = !(!$quoted && preg_match('/\shx-headers\b/i', $open) === 1);
        if ($quoted) {
            $own = json_decode(html_entity_decode(($existing[2] ?? '') !== '' ? $existing[2] : $existing[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!is_array($own) || ($own !== [] && array_is_list($own))) {
                $carry = false;
            } else {
                $open = str_replace($existing[0], '', $open);
                $headers += $own;
            }
        }
        // Kept out of htmx's history cache (localStorage): what the page carries (a preview token, a
        // draft) never outlives it there. A value the page set itself is replaced.
        $open = preg_replace('/\s(?:data-)?hx-history\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $open) ?? $open;
        $attribute = ($carry ? self::headersAttribute($headers) : '') . ' hx-history="false"';
        $open = preg_replace('#\s*/?>$#', $attribute . '>', $open, 1) ?? $open;

        return substr_replace($html, $open, $at, strlen($tag[0][0]));
    }

    /**
     * A whole document without a <body> tag (the browser makes one): a <body> with the headers and
     * hx-history="false" after its </head> (else its <html> tag). A fragment (no <html>) is left as
     * it is: it goes into a page that carries them already.
     *
     * @param array<string, string> $headers
     */
    private static function withBody(string $html, string $masked, array $headers): string
    {
        $body = '<body' . self::headersAttribute($headers) . ' hx-history="false">';
        if (preg_match('#</head\s*>#i', $masked, $head, PREG_OFFSET_CAPTURE) === 1) {
            return substr_replace($html, $body, $head[0][1] + strlen($head[0][0]), 0);
        }
        if (preg_match('/<html\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $masked, $root, PREG_OFFSET_CAPTURE) === 1) {
            return substr_replace($html, $body, $root[0][1] + strlen($root[0][0]), 0);
        }

        return $html;
    }

    /** @param array<string, string> $headers */
    private static function headersAttribute(array $headers): string
    {
        $json = json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        return ' hx-headers="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"';
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
