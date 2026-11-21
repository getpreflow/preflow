<?php

declare(strict_types=1);

namespace Preflow\View;

use Preflow\Core\Context\Contexts;

/**
 * The CSS, JS and head tags a render collects, printed into its page with the CSP nonce.
 *
 * Since 0.81 each render frame has its own registries (a frame slot, AssetRegistry): inside a
 * render every call works on the current frame's, outside every render on the collector's own.
 * A frame starts from what is registered outside every render (the outermost frame of a
 * request) or from what boot registered (markBooted(); a render inside another one: the SEO
 * audit, GEO, a page cache rebuild, verify, a request a page asks for), with nothing printed
 * and no CSS scope; what it collects stays in it. Contexts::within() keeps the frame, so it
 * keeps its assets too. A forked collector (fork()) keeps registries of its own everywhere.
 */
final class AssetCollector
{
    /** Stands for the render's nonce in a head tag (addHeadTag()); printed as the nonce in use. @since 0.81.0 */
    public const string NONCE = '{preflow:csp-nonce}';

    /** The registries outside every render, and everywhere for a forked collector. */
    private AssetRegistry $own;

    /** What boot registered (markBooted()); null: nothing was marked. */
    private ?AssetRegistry $booted = null;

    private bool $frameBound = true;

    /** @var (\Closure(): AssetRegistry)|null builds a frame's registry; made once, not on every read */
    private ?\Closure $registryMaker = null;

    public function __construct(
        private readonly NonceGenerator $nonceGenerator,
        private readonly bool $isProd = false,
        private readonly ?string $cachePath = null,
    ) {
        $this->own = new AssetRegistry();
    }

    /**
     * Set the CSS scope for subsequent addCss() calls.
     * Pass null to clear scoping.
     */
    public function setCssScope(?string $scope): void
    {
        $this->registry()->cssScope = $scope;
    }

    /**
     * Get the current CSS scope.
     */
    public function getCssScope(): ?string
    {
        return $this->registry()->cssScope;
    }

    /**
     * Register an extra tag to include in <head> (e.g., library script tags,
     * preload links). With a key, the tag is added once per page.
     */
    public function addHeadTag(string $tag, ?string $key = null): void
    {
        $registry = $this->registry();
        if ($key === null) {
            $registry->headTags[] = $tag;
            return;
        }
        $registry->headTags[$key] ??= $tag;
    }

    /**
     * With a CSS scope set (a component with scoping), $css is wrapped in the scope's class
     * (native CSS nesting); @media, @supports and the like stay inside and scope their rules.
     * At-rules that can't be nested (@keyframes, @font-face, @property, @counter-style,
     * @font-palette-values, @font-feature-values; vendor-prefixed too) are kept before the
     * scoped block, as written: their names (a keyframes' name, a font family) stay global.
     */
    public function addCss(string $css, ?string $key = null): void
    {
        $registry = $this->registry();
        // Wrap in scope selector if CSS scoping is active (native CSS nesting)
        if ($registry->cssScope !== null) {
            $css = self::scoped($css, $registry->cssScope);
        }

        $key ??= hash('xxh3', $css);
        $registry->css[$key] ??= $css;
    }

    /** @var array<string, string> scoped CSS by scope and text, for the process (a component renders many times) */
    private static array $scopedCache = [];

    /** Entries kept in $scopedCache before it starts over. */
    private const SCOPED_CACHE_SIZE = 256;

    /** $css nested under the class $scope, with the at-rules that can't be nested before it. */
    private static function scoped(string $css, string $scope): string
    {
        $key = $scope . "\0" . hash('xxh128', $css);
        if (isset(self::$scopedCache[$key])) {
            return self::$scopedCache[$key];
        }
        [$global, $nested] = self::splitUnnestable($css);
        $scoped = ($global !== '' ? $global . "\n" : '')
            . (trim($nested) !== '' ? '.' . $scope . " {\n" . $nested . "\n}" : '');
        if (count(self::$scopedCache) >= self::SCOPED_CACHE_SIZE) {
            self::$scopedCache = [];
        }

        return self::$scopedCache[$key] = $scoped;
    }

    /** At-rules a scope's nesting would drop (lowercase, without a vendor prefix). */
    private const UNNESTABLE_AT_RULES = ['keyframes', 'font-face', 'property', 'counter-style', 'font-palette-values', 'font-feature-values'];

    /**
     * The top-level at-rules of $css that can't be nested, and the rest.
     *
     * @return array{string, string} [those at-rules, one per line; the rest]
     */
    private static function splitUnnestable(string $css): array
    {
        if (stripos($css, '@') === false) {
            return ['', $css];
        }
        $global = [];
        $rest = '';
        $length = strlen($css);
        $depth = 0;
        $from = 0;
        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
            } elseif ($char === '"' || $char === "'") {
                $i = self::stringEnd($css, $i);
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($char === '@' && $depth === 0
                && preg_match('/\G@(?:-[a-z]+-)?([a-z-]+)/i', $css, $m, 0, $i) === 1
                && in_array(strtolower($m[1]), self::UNNESTABLE_AT_RULES, true)
            ) {
                $end = self::blockEnd($css, $i);
                $rest .= substr($css, $from, $i - $from);
                $global[] = substr($css, $i, $end - $i + 1);
                $from = $i = $end + 1;
                $i--;
            }
        }
        $rest .= substr($css, $from);

        return [implode("\n", $global), $rest];
    }

    /** The offset of the quote that closes the string opening at $start (the end of $css if none). */
    private static function stringEnd(string $css, int $start): int
    {
        $quote = $css[$start];
        $length = strlen($css);
        for ($i = $start + 1; $i < $length; $i++) {
            if ($css[$i] === '\\') {
                $i++;
            } elseif ($css[$i] === $quote || $css[$i] === "\n") {
                return $i;
            }
        }

        return $length - 1;
    }

    /** The offset of the `}` closing the at-rule at $start, or of its `;` when it has no block. */
    private static function blockEnd(string $css, int $start): int
    {
        $length = strlen($css);
        $depth = 0;
        for ($i = $start; $i < $length; $i++) {
            $char = $css[$i];
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
            } elseif ($char === '"' || $char === "'") {
                $i = self::stringEnd($css, $i);
            } elseif ($char === ';' && $depth === 0) {
                return $i;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth <= 0) {
                return $i;
            }
        }

        return $length - 1;
    }

    public function addJs(
        string $js,
        JsPosition $position = JsPosition::Body,
        ?string $key = null,
    ): void {
        $key ??= hash('xxh3', $js);
        $registry = $this->registry();
        match ($position) {
            JsPosition::Head => $registry->jsHead[$key] ??= $js,
            JsPosition::Body => $registry->jsBody[$key] ??= $js,
            JsPosition::Inline => $registry->jsInline[$key] ??= $js,
        };
    }

    /**
     * Render for <head>: CSS + head JS + library tags (e.g., HTMX).
     */
    public function renderHead(): string
    {
        // Only what wasn't printed yet: a layout's {{ head() }} may run before the
        // page's CSS is collected; injectInto() adds the rest before </head>.
        $registry = $this->registry();
        $out = '';
        if (($css = $this->pending($registry, 'css', $registry->css)) !== []) {
            $out .= $this->renderCssBlock($css);
        }
        $out .= $this->renderJsBlock($this->pending($registry, 'jsHead', $registry->jsHead));
        $tags = implode("\n", $this->pending($registry, 'headTags', $registry->headTags));
        // A tag that holds the nonce (the htmx config) gets the one of the render printing it.
        return $out . (str_contains($tags, self::NONCE) ? str_replace(self::NONCE, $this->nonceGenerator->get(), $tags) : $tags);
    }

    /**
     * Put the collected assets into a full HTML document: head assets before
     * </head>, body JS before </body>. Responses rendered outside the page
     * pipeline (e.g. Folio's frontend) use this too, so CSS collected while
     * rendering (components, focal-point rules) reaches the page.
     */
    public function injectInto(string $html): string
    {
        // What the template already printed ({{ head() }} / {{ assets() }}) is not added again;
        // whatever was collected after those calls is.
        $head = $this->renderHead();
        $headAt = stripos($html, '</head>');
        if ($head !== '' && $headAt !== false) {
            $html = substr_replace($html, $head, $headAt, 0);
        }
        $body = $this->renderAssets();
        $bodyAt = strripos($html, '</body>');
        if ($body !== '' && $bodyAt !== false) {
            $html = substr_replace($html, $body, $bodyAt, 0);
        }

        return $html;
    }

    /**
     * Render for end of <body>: body JS, then inline JS (js('inline'); a component action's
     * response prints it after its fragment).
     */
    public function renderAssets(): string
    {
        $registry = $this->registry();

        return $this->renderJsBlock($this->pending($registry, 'jsBody', $registry->jsBody))
            . $this->renderJsBlock($this->pending($registry, 'jsInline', $registry->jsInline));
    }

    /**
     * Entries of a registry not printed yet, now marked as printed.
     *
     * @param array<string|int, string> $entries
     * @return array<string|int, string>
     */
    private function pending(AssetRegistry $registry, string $name, array $entries): array
    {
        $new = array_diff_key($entries, $registry->printed[$name]);
        $registry->printed[$name] += array_fill_keys(array_keys($new), true);

        return $new;
    }

    public function renderCss(): string
    {
        return $this->renderCssBlock($this->registry()->css);
    }

    /** @param array<string|int, string> $registry */
    private function renderCssBlock(array $registry): string
    {
        if ($registry === []) {
            return '';
        }

        $css = $this->isProd
            ? implode('', array_map($this->minifyCss(...), $registry))
            : implode("\n", $registry);

        $nonce = $this->nonceAttr();

        return "<style{$nonce}>{$css}</style>\n";
    }

    public function renderJsHead(): string
    {
        return $this->renderJsBlock($this->registry()->jsHead);
    }

    public function renderJsBody(): string
    {
        return $this->renderJsBlock($this->registry()->jsBody);
    }

    public function renderJsInline(): string
    {
        return $this->renderJsBlock($this->registry()->jsInline);
    }

    private function renderJsBlock(array $registry): string
    {
        if ($registry === []) {
            return '';
        }

        $js = $this->isProd
            ? implode(";\n", array_filter(array_map($this->minifyJs(...), $registry), static fn (string $j): bool => $j !== ''))
            : implode("\n", $registry);

        $nonce = $this->nonceAttr();

        return "<script{$nonce}>{$js}</script>\n";
    }

    private function minifyCss(string $css): string
    {
        return $this->cached('css', $css, static fn (): string => (new \MatthiasMullie\Minify\CSS($css))->minify());
    }

    private function minifyJs(string $js): string
    {
        return $this->cached('js', $js, static fn (): string => (new \MatthiasMullie\Minify\JS($js))->minify());
    }

    /**
     * Minified output is cached by content: key = xxh3(minifier version + kind + input),
     * stored as a PHP file returning the string (opcache-friendly), written atomically.
     */
    private function cached(string $kind, string $input, \Closure $minify): string
    {
        if ($this->cachePath === null) {
            return $minify();
        }

        $dir = rtrim($this->cachePath, '/') . '/assets';
        $file = $dir . '/' . hash('xxh3', self::minifierVersion() . "\0" . $kind . "\0" . $input) . '.php';

        if (is_file($file)) {
            try {
                $hit = @include $file;
            } catch (\Throwable) {
                $hit = null;
            }
            if (is_string($hit)) {
                return $hit;
            }
            @unlink($file); // truncated or corrupt entry
        }

        $out = $minify();

        if (is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir)) {
            $this->prune($dir);
            $payload = '<?php return ' . var_export($out, true) . ";\n";
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $payload) === strlen($payload)) {
                if (!@rename($tmp, $file)) {
                    @unlink($tmp);
                }
            } else {
                @unlink($tmp);
            }
        }

        return $out;
    }

    private static ?string $minifierVersion = null;

    private static function minifierVersion(): string
    {
        return self::$minifierVersion ??= class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled('matthiasmullie/minify')
                ? (string) \Composer\InstalledVersions::getReference('matthiasmullie/minify')
                    . (string) \Composer\InstalledVersions::getPrettyVersion('matthiasmullie/minify')
                : 'unknown';
    }

    /** Safety cap: above 2000 entries, a miss drops the oldest 10% by mtime. */
    private function prune(string $dir): void
    {
        $files = glob($dir . '/*.php') ?: [];
        if (count($files) <= 2000) {
            return;
        }
        $times = [];
        foreach ($files as $f) {
            $times[$f] = @filemtime($f) ?: 0;
        }
        asort($times);
        foreach (array_slice(array_keys($times), 0, intdiv(count($times), 10)) as $f) {
            @unlink($f);
        }
    }

    private function nonceAttr(): string
    {
        $nonce = $this->nonceGenerator->get();
        return " nonce=\"{$nonce}\"";
    }

    public function getNonce(): string
    {
        return $this->nonceGenerator->get();
    }

    /**
     * Create an isolated copy with empty registries but the same nonce generator.
     * Use for sub-renders (e.g., iframe preview pages) that need their own asset scope. The copy
     * keeps its registries itself, inside renders too (since 0.81.0).
     */
    public function fork(): self
    {
        $fork = new self($this->nonceGenerator, $this->isProd, $this->cachePath);
        $fork->frameBound = false;

        return $fork;
    }

    /**
     * Keeps what is registered now as what boot registered (the application calls it once its
     * boot is done: htmx with include 'always', what providers and apps add at boot). A render
     * inside another one starts from it.
     *
     * @since 0.81.0
     */
    public function markBooted(): void
    {
        $this->booted = $this->registry()->registered();
    }

    /**
     * Run $render with nothing marked as printed and no CSS scope, then put both back: a page
     * rendered after another one failed part-way (an app's error page) gets every collected asset,
     * even those the failed page's head had printed already.
     *
     * @since 0.66
     * @template T
     * @param callable(): T $render
     * @return T
     */
    public function reprinting(callable $render): mixed
    {
        $registry = $this->registry();
        $saved = [$registry->printed, $registry->cssScope];
        $registry->printed = AssetRegistry::NOTHING_PRINTED;
        $registry->cssScope = null;

        try {
            return $render();
        } finally {
            [$registry->printed, $registry->cssScope] = $saved;
        }
    }

    /**
     * The registries the calls work on: the current render frame's (made on first use from what
     * is registered outside every render for the outermost frame, else from what boot
     * registered), the own ones outside every render and for a forked collector.
     */
    private function registry(): AssetRegistry
    {
        if (!$this->frameBound) {
            return $this->own;
        }

        return Contexts::slot(AssetRegistry::class, $this->registryMaker ??= fn (): AssetRegistry => (Contexts::depth() === 1 ? $this->own : ($this->booted ?? $this->own))->registered())
            ?? $this->own;
    }

    public function hasCss(): bool
    {
        return $this->registry()->css !== [];
    }

    public function hasJs(): bool
    {
        $registry = $this->registry();

        return $registry->jsHead !== [] || $registry->jsBody !== [] || $registry->jsInline !== [];
    }

    // -----------------------------------------------------------------------
    // Stats — used by DebugCollector
    // -----------------------------------------------------------------------

    public function getCssCount(): int
    {
        return count($this->registry()->css);
    }

    public function getJsCount(): int
    {
        $registry = $this->registry();

        return count($registry->jsHead) + count($registry->jsBody) + count($registry->jsInline);
    }

    public function getCssBytes(): int
    {
        return array_sum(array_map('strlen', $this->registry()->css));
    }

    public function getJsBytes(): int
    {
        $registry = $this->registry();

        return array_sum(array_map('strlen', $registry->jsHead))
            + array_sum(array_map('strlen', $registry->jsBody))
            + array_sum(array_map('strlen', $registry->jsInline));
    }
}
