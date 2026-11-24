<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Core\Context\Contexts;
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
        'outer' => 'outerHTML', 'inner' => 'innerHTML',
        'prepend' => 'afterbegin', 'append' => 'beforeend', 'delete' => 'delete', 'none' => 'none',
    ];

    /** The neutral events (ActionOptions::EVENTS) as hx-trigger events. */
    private const array EVENTS = [
        'click' => 'click', 'submit' => 'submit', 'change' => 'change', 'input' => 'input', 'load' => 'load', 'visible' => 'revealed',
        'blur' => 'blur',
    ];

    /**
     * The form attribute of a state carrier: an id no form has, so the carrier belongs to no form
     * of the page (a form around the component never posts it on its own).
     */
    private const string NO_FORM = 'preflow-no-form';

    /** The attributes a call sets for its state and target: not to be given under the `htmx` key. */
    private const array OWN_ATTRIBUTES = ['hx-include', 'hx-vals', 'hx-sync', 'hx-target'];

    private const int JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private ?HtmxAssets $assets = null;

    /** Lets attribute helpers mark htmx as needed on the page (include mode "on-demand"). */
    public function useAssets(HtmxAssets $assets): void
    {
        $this->assets = $assets;
    }

    private WarnedOptions $warned;

    /**
     * @param bool $debug misused action options fail (callAttributes()), others' keys are warned about
     * @param (\Closure(string): void)|null $log where warnings go (error_log() without one)
     */
    public function __construct(
        private readonly ResponseHeaders $responseHeaders,
        private readonly bool $debug = false,
        private readonly ?\Closure $log = null,
    ) {
        $this->warned = new WarnedOptions();
    }

    /**
     * hx-get/hx-post, hx-target, hx-swap (with `show:…:top` for scroll), hx-trigger (a debounce
     * delays typing only), hx-include (the state carrier first, then the regions and fields
     * named), hx-sync (one request per component at a time: `queue all` on the component, or
     * with `latest` a newer request replaces the one running on it), hx-disabled-elt, and hx-vals
     * with the values (VALUES_FIELD, one JSON object) and the region (REGION_FIELD, empty for the
     * whole component).
     *
     * Both fields are always written, so a call inside another call's element (a button in a form
     * that calls an action) never sends the outer call's values or region: htmx merges hx-vals of
     * every ancestor, the element's own keys first. hx-disinherit names the attributes the call set
     * (hx-target, hx-swap, hx-include, hx-sync, hx-disabled-elt, hx-trigger and the escape hatch's),
     * so they never reach the elements inside it; everything else inherits as htmx does.
     *
     * Attributes under the `htmx` key are added last, as given, except the ones the call needs
     * for its state and target (hx-include, hx-vals, hx-sync, hx-target): those fail in debug and
     * are left out, with a log line, otherwise. Another driver's key is ignored (in debug with one
     * warning per key and request).
     *
     * @since 0.86.0
     */
    public function callAttributes(ActionCall $call): array
    {
        return $this->attributes($call, null);
    }

    /**
     * callAttributes() with hx-trigger `{event} from:body`: htmx fires an answer's HX-Trigger
     * events on the body.
     *
     * @since 0.86.0
     */
    public function listenAttributes(ActionCall $call, string $event): array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/', $event) !== 1) {
            throw new \InvalidArgumentException(sprintf("An event name is a letter, then letters, digits, -, _, . and :, not '%s'.", $event));
        }
        if ($call->options->on !== []) {
            throw new \InvalidArgumentException(sprintf("A call that listens to '%s' runs on that event: the action option on (%s) is not for it.", $event, implode(', ', $call->options->on)));
        }

        return $this->attributes($call, $event);
    }

    /**
     * @return array<string, string>
     */
    private function attributes(ActionCall $call, ?string $listen): array
    {
        $this->assets?->requireBusyMarker();
        $options = $call->options;
        $component = '#' . self::selector($call->componentId);

        $swap = self::SWAPS[$options->swap];
        if ($options->scroll === 'top') {
            $swap .= ' show:#' . self::selector($call->targetId) . ':top';
        }
        $include = ['#' . self::selector(self::carrierId($call->componentId))];
        foreach ($options->include as $name) {
            $include[] = '#' . self::selector($call->componentId . '-' . $name); // a region: its fields
            $include[] = $component . ' [name="' . $name . '"]'; // a field of the component
        }
        $attributes = [
            "hx-{$call->method}" => $call->url,
            'hx-target' => '#' . self::selector($call->targetId),
            'hx-swap' => $swap,
            'hx-include' => implode(', ', $include),
            'hx-sync' => $component . ':' . ($options->latest ? 'replace' : 'queue all'),
            'hx-vals' => json_encode([
                self::VALUES_FIELD => $call->values === [] ? '{}' : json_encode(self::plain(self::VALUES_FIELD, $call->values), self::JSON),
                self::REGION_FIELD => $options->region() ?? '',
            ], self::JSON),
        ];
        if ($listen !== null) {
            $attributes['hx-trigger'] = $listen . ' from:body';
        } elseif ($options->on !== []) {
            $attributes['hx-trigger'] = implode(', ', array_map(
                static fn (string $on): string => self::EVENTS[$on] . ($on === 'input' && $options->debounce !== null ? ' delay:' . $options->debounce . 'ms' : ''),
                $options->on,
            ));
        }
        if ($options->disable !== null) {
            $attributes['hx-disabled-elt'] = $options->disable === 'self' ? 'this' : '#' . self::selector($call->componentId . '-' . $options->disable);
        }
        foreach ($options->drivers() as $driver) {
            if ($driver !== self::OPTIONS_KEY && $this->debug) {
                $this->warnOnce($driver, sprintf("Action option '%s' is another driver's attributes: %s ignores it (its own key is '%s').", $driver, self::class, self::OPTIONS_KEY));
            }
        }
        foreach ($options->driverOptions(self::OPTIONS_KEY) as $name => $value) {
            $name = (string) $name;
            if (in_array(strtolower((string) preg_replace('/^data-/i', '', $name)), self::OWN_ATTRIBUTES, true)) {
                $message = sprintf("The htmx attribute %s can't be given under the action option htmx: the call sets it for its state and target (%s are the call's own).", $name, implode(', ', self::OWN_ATTRIBUTES));
                if ($this->debug) {
                    throw new \InvalidArgumentException($message);
                }
                $this->log($message . ' Left out.');
                continue;
            }
            $attributes[$name] = is_scalar($value) || $value instanceof \Stringable
                ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)
                : json_encode($value, self::JSON);
        }
        // Only what the call set stays on its element: calls (and old-style elements) inside it keep their own.
        $set = [];
        foreach (array_keys($attributes) as $name) {
            $name = strtolower((string) preg_replace('/^data-/i', '', (string) $name));
            if (str_starts_with($name, 'hx-') && !in_array($name, ['hx-get', 'hx-post', 'hx-vals'], true)) {
                $set[$name] = true;
            }
        }
        $attributes['hx-disinherit'] = implode(' ', array_keys($set));

        return $attributes;
    }

    /**
     * A hidden input `preflow-state--{componentId}` named STATE_FIELD[componentId], outside every
     * form (its form attribute names none), which callAttributes() includes. Component ids start
     * with a class name and a hash, never with `preflow-state--`, so the id is no other element's.
     *
     * @since 0.86.0
     */
    public function stateCarrier(string $componentId, string $carried): string
    {
        return '<input type="hidden" id="' . self::escape(self::carrierId($componentId)) . '" name="'
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

    private static function carrierId(string $componentId): string
    {
        return 'preflow-state--' . $componentId;
    }

    /**
     * $id as a CSS id selector part: every character but letters, digits, - and _ (and a leading
     * digit) as a six-digit hex escape, so the selector has no `:`, `,` or space htmx would split on.
     */
    private static function selector(string $id): string
    {
        $out = '';
        foreach (mb_str_split($id, 1, 'UTF-8') as $i => $char) {
            $plain = preg_match('/^[A-Za-z0-9_-]$/', $char) === 1 && !($i === 0 && ctype_digit($char));
            $out .= $plain ? $char : sprintf('\\%06x', mb_ord($char, 'UTF-8'));
        }

        return $out;
    }

    /**
     * $values as plain values for JSON: true is "1", false and null are "", a backed enum its
     * value, a Stringable its text.
     *
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function plain(string $path, array $values): array
    {
        $plain = [];
        foreach ($values as $name => $value) {
            $field = $path . '[' . $name . ']';
            $plain[$name] = match (true) {
                is_array($value) => self::plain($field, $value),
                is_int($value), is_float($value), is_string($value) => $value,
                is_bool($value), $value === null => $value === true ? '1' : '',
                $value instanceof \Stringable => (string) $value,
                $value instanceof \BackedEnum => $value->value,
                default => throw new \InvalidArgumentException(sprintf('The action value %s is a %s: values are text, numbers, true/false, null, backed enums or lists of them.', $field, get_debug_type($value))),
            };
        }

        return $plain;
    }

    /** One warning per key and request (the render frame; the driver's own outside every render). */
    private function warnOnce(string $key, string $message): void
    {
        $warned = Contexts::depth() === 0 ? $this->warned : Contexts::frame()->slot(WarnedOptions::class, static fn (): WarnedOptions => new WarnedOptions());
        if (isset($warned->keys[$key])) {
            return;
        }
        $warned->keys[$key] = true;
        $this->log($message);
    }

    private function log(string $message): void
    {
        $this->log !== null ? ($this->log)($message) : error_log($message);
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
