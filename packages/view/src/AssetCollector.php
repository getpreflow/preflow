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

    public function __construct(
        private readonly NonceGenerator $nonceGenerator,
        private readonly bool $isProd = false,
    ) {}

    /**
     * Register an extra tag to include in <head> (e.g., library script tags).
     */
    public function addHeadTag(string $tag): void
    {
        $this->headTags[] = $tag;
    }

    public function addCss(string $css, ?string $key = null): void
    {
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
        $out = $this->renderCss();
        $out .= $this->renderJsHead();
        $out .= implode("\n", $this->headTags);
        return $out;
    }

    /**
     * Render for end of <body>: body JS only.
     */
    public function renderAssets(): string
    {
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
        // Remove comments
        $css = preg_replace('!/\*.*?\*/!s', '', $css);
        // Remove whitespace around selectors and braces
        $css = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', $css);
        // Collapse remaining whitespace
        $css = preg_replace('/\s+/', ' ', $css);
        // Remove trailing semicolons before closing braces
        $css = str_replace(';}', '}', $css);

        return trim($css);
    }

    private function minifyJs(string $js): string
    {
        // Remove single-line comments (but not URLs with //)
        $js = preg_replace('#(?<!:)//[^\n]*#', '', $js);
        // Remove multi-line comments
        $js = preg_replace('!/\*.*?\*/!s', '', $js);
        // Collapse whitespace around operators and braces
        $js = preg_replace('/\s*([{}();,=:+\-<>!&|?])\s*/', '$1', $js);
        // Collapse remaining whitespace runs
        $js = preg_replace('/\s+/', ' ', $js);

        return trim($js);
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
