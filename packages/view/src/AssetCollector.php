<?php

declare(strict_types=1);

namespace Preflow\View;

final class AssetCollector
{
    /** @var array<string, string> hash => css */
    private array $cssRegistry = [];

    /** @var array<string, string> hash => js */
    private array $jsHead = [];

    /** @var array<string, string> hash => js */
    private array $jsBody = [];

    /** @var array<string, string> hash => js */
    private array $jsInline = [];

    /** @var string[] Extra tags to include in <head> (e.g., HTMX script tag) */
    private array $headTags = [];

    /** @var string|null Active CSS scope class — when set, addCss() wraps content in this selector */
    private ?string $cssScope = null;

    /** @var array<string, array<string|int, true>> keys already printed by renderHead()/renderAssets(), per registry */
    private array $printed = self::NOTHING_PRINTED;

    private const NOTHING_PRINTED = ['css' => [], 'jsHead' => [], 'jsBody' => [], 'jsInline' => [], 'headTags' => []];

    public function __construct(
        private readonly NonceGenerator $nonceGenerator,
        private readonly bool $isProd = false,
        private readonly ?string $cachePath = null,
    ) {}

    /**
     * Set the CSS scope for subsequent addCss() calls.
     * Pass null to clear scoping.
     */
    public function setCssScope(?string $scope): void
    {
        $this->cssScope = $scope;
    }

    /**
     * Get the current CSS scope.
     */
    public function getCssScope(): ?string
    {
        return $this->cssScope;
    }

    /**
     * Register an extra tag to include in <head> (e.g., library script tags,
     * preload links). With a key, the tag is added once per page.
     */
    public function addHeadTag(string $tag, ?string $key = null): void
    {
        if ($key === null) {
            $this->headTags[] = $tag;
            return;
        }
        $this->headTags[$key] ??= $tag;
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
        // Wrap in scope selector if CSS scoping is active (native CSS nesting)
        if ($this->cssScope !== null) {
            [$global, $scoped] = self::splitUnnestable($css);
            $css = ($global !== '' ? $global . "\n" : '')
                . (trim($scoped) !== '' ? '.' . $this->cssScope . " {\n" . $scoped . "\n}" : '');
        }

        $key ??= hash('xxh3', $css);
        $this->cssRegistry[$key] ??= $css;
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
        match ($position) {
            JsPosition::Head => $this->jsHead[$key] ??= $js,
            JsPosition::Body => $this->jsBody[$key] ??= $js,
            JsPosition::Inline => $this->jsInline[$key] ??= $js,
        };
    }

    /**
     * Render for <head>: CSS + head JS + library tags (e.g., HTMX).
     */
    public function renderHead(): string
    {
        // Only what wasn't printed yet: a layout's {{ head() }} may run before the
        // page's CSS is collected; injectInto() adds the rest before </head>.
        $out = '';
        if (($css = $this->pending('css', $this->cssRegistry)) !== []) {
            $out .= $this->renderCssBlock($css);
        }
        $out .= $this->renderJsBlock($this->pending('jsHead', $this->jsHead));
        $out .= implode("\n", $this->pending('headTags', $this->headTags));
        return $out;
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
        return $this->renderJsBlock($this->pending('jsBody', $this->jsBody))
            . $this->renderJsBlock($this->pending('jsInline', $this->jsInline));
    }

    /**
     * Entries of a registry not printed yet, now marked as printed.
     *
     * @param array<string|int, string> $registry
     * @return array<string|int, string>
     */
    private function pending(string $name, array $registry): array
    {
        $new = array_diff_key($registry, $this->printed[$name]);
        $this->printed[$name] += array_fill_keys(array_keys($new), true);

        return $new;
    }

    public function renderCss(): string
    {
        return $this->renderCssBlock($this->cssRegistry);
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
        return $this->renderJsBlock($this->jsHead);
    }

    public function renderJsBody(): string
    {
        return $this->renderJsBlock($this->jsBody);
    }

    public function renderJsInline(): string
    {
        return $this->renderJsBlock($this->jsInline);
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
     * Use for sub-renders (e.g., iframe preview pages) that need their own asset scope.
     */
    public function fork(): self
    {
        return new self($this->nonceGenerator, $this->isProd, $this->cachePath);
    }

    /**
     * Run $render with empty registries on this same instance (the engines hold
     * it), then put the previous state back: a sub-render (a page rendered for
     * its Markdown) neither sees the request's assets nor leaves its own behind.
     *
     * @template T
     * @param callable(): T $render
     * @return T
     */
    public function isolate(callable $render): mixed
    {
        $saved = [$this->cssRegistry, $this->jsHead, $this->jsBody, $this->jsInline, $this->headTags, $this->cssScope, $this->printed];
        $this->cssRegistry = $this->jsHead = $this->jsBody = $this->jsInline = $this->headTags = [];
        $this->cssScope = null;
        $this->printed = self::NOTHING_PRINTED;

        try {
            return $render();
        } finally {
            [$this->cssRegistry, $this->jsHead, $this->jsBody, $this->jsInline, $this->headTags, $this->cssScope, $this->printed] = $saved;
        }
    }

    /**
     * Run $render on the registries as they are, then put them back: pages rendered
     * one after another in one process (the SEO audit) each start from what boot
     * registered, and none sees what an earlier one added or printed.
     *
     * @since 0.54
     * @template T
     * @param callable(): T $render
     * @return T
     */
    public function restoring(callable $render): mixed
    {
        $saved = [$this->cssRegistry, $this->jsHead, $this->jsBody, $this->jsInline, $this->headTags, $this->cssScope, $this->printed];

        try {
            return $render();
        } finally {
            [$this->cssRegistry, $this->jsHead, $this->jsBody, $this->jsInline, $this->headTags, $this->cssScope, $this->printed] = $saved;
        }
    }

    public function hasCss(): bool
    {
        return $this->cssRegistry !== [];
    }

    public function hasJs(): bool
    {
        return $this->jsHead !== [] || $this->jsBody !== [] || $this->jsInline !== [];
    }

    // -----------------------------------------------------------------------
    // Stats — used by DebugCollector
    // -----------------------------------------------------------------------

    public function getCssCount(): int
    {
        return count($this->cssRegistry);
    }

    public function getJsCount(): int
    {
        return count($this->jsHead) + count($this->jsBody) + count($this->jsInline);
    }

    public function getCssBytes(): int
    {
        return array_sum(array_map('strlen', $this->cssRegistry));
    }

    public function getJsBytes(): int
    {
        return array_sum(array_map('strlen', $this->jsHead))
            + array_sum(array_map('strlen', $this->jsBody))
            + array_sum(array_map('strlen', $this->jsInline));
    }
}
