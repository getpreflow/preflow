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

    private bool $headRendered = false;
    private bool $bodyRendered = false;

    public function __construct(
        private readonly NonceGenerator $nonceGenerator,
        private readonly bool $isProd = false,
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
     * Register an extra tag to include in <head> (e.g., library script tags).
     */
    public function addHeadTag(string $tag): void
    {
        $this->headTags[] = $tag;
    }

    public function addCss(string $css, ?string $key = null): void
    {
        // Wrap in scope selector if CSS scoping is active (native CSS nesting)
        if ($this->cssScope !== null) {
            $css = '.' . $this->cssScope . " {\n" . $css . "\n}";
        }

        $key ??= hash('xxh3', $css);
        $this->cssRegistry[$key] ??= $css;
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
        $this->headRendered = true;
        $out = $this->renderCss();
        $out .= $this->renderJsHead();
        $out .= implode("\n", $this->headTags);
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
        // What the template already printed ({{ head() }} / {{ assets() }}) is not added again.
        $head = $this->headRendered ? '' : $this->renderHead();
        $headAt = stripos($html, '</head>');
        if ($head !== '' && $headAt !== false) {
            $html = substr_replace($html, $head, $headAt, 0);
        }
        $body = $this->bodyRendered ? '' : $this->renderAssets();
        $bodyAt = strripos($html, '</body>');
        if ($body !== '' && $bodyAt !== false) {
            $html = substr_replace($html, $body, $bodyAt, 0);
        }

        return $html;
    }

    /**
     * Render for end of <body>: body JS only.
     */
    public function renderAssets(): string
    {
        $this->bodyRendered = true;
        return $this->renderJsBody();
    }

    public function renderCss(): string
    {
        if ($this->cssRegistry === []) {
            return '';
        }

        $css = implode("\n", $this->cssRegistry);

        if ($this->isProd) {
            $css = $this->minifyCss($css);
        }

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

        $js = implode("\n", $registry);

        if ($this->isProd) {
            $js = $this->minifyJs($js);
        }

        $nonce = $this->nonceAttr();

        return "<script{$nonce}>{$js}</script>\n";
    }

    private function minifyCss(string $css): string
    {
        return (new \MatthiasMullie\Minify\CSS($css))->minify();
    }

    private function minifyJs(string $js): string
    {
        return (new \MatthiasMullie\Minify\JS($js))->minify();
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
        return new self($this->nonceGenerator, $this->isProd);
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
