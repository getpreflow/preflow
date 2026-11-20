<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Core\Context\Contexts;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;

/**
 * Inlines the vendored htmx build through the AssetCollector (same nonce and
 * dedup as component JS), so pages make no external request for it. Also hands
 * htmx the page nonce, so scripts/styles it inserts from fragment responses
 * are accepted by the page's CSP.
 *
 * Modes (config/htmx.php 'include'): always | on-demand | never.
 *
 * Since 0.81 whether a page needs htmx is kept per render frame (a frame slot), like the assets
 * themselves: a render asks for htmx in its own frame. With 'always', every frame starts out
 * needing it (boot registered it).
 */
final class HtmxAssets
{
    private const MODES = ['always', 'on-demand', 'never'];

    /** Whether htmx is needed outside every render. */
    private HtmxRequirement $own;

    /** @var array<string, string> the htmx build by path, read once per process */
    private static array $scripts = [];

    public function __construct(
        private readonly AssetCollector $assets,
        private readonly string $mode,
        private readonly string $scriptPath = __DIR__ . '/../assets/htmx.min.js',
    ) {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid htmx include mode "%s" in config/htmx.php; use one of: %s.',
                $mode,
                implode(', ', self::MODES),
            ));
        }
        $this->own = new HtmxRequirement();
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** The page's CSP nonce, which htmx's fragments announce in HX-Nonce. */
    public function nonce(): string
    {
        return $this->assets->getNonce();
    }

    public function require(): void
    {
        if ($this->mode === 'never') {
            return;
        }
        // Added every time, keyed: the collector keeps one of each per render frame, so every
        // render that needs htmx (verify's second render, a page cache rebuild) gets it.
        $this->requirement()->required = true;

        // The nonce of the render that prints the tag (boot registers it once for 'always').
        $nonce = AssetCollector::NONCE;
        $config = json_encode(
            ['inlineScriptNonce' => $nonce, 'inlineStyleNonce' => $nonce],
            JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        // htmx reads this meta on DOM ready, so its position after the script is fine.
        $this->assets->addHeadTag('<meta name="htmx-config" content=\'' . $config . '\'>', 'preflow-htmx-config');
        $this->assets->addJs(self::$scripts[$this->scriptPath] ??= (string) file_get_contents($this->scriptPath), JsPosition::Head, 'preflow-htmx');
        $this->assets->addJs(self::NONCE_GUARD, JsPosition::Head, 'preflow-htmx-nonce-guard');
    }

    /**
     * htmx re-stamps every <script> it swaps in with the page nonce
     * (inlineScriptNonce), which would let an injected script run as trusted.
     * Before a swap, drop every script/style whose nonce is not the one the
     * server announced for that response in the HX-Nonce header. htmx does not
     * re-stamp <style>, so the guard gives the surviving ones (the server's own)
     * the page nonce, or the page's CSP would block the fragment's CSS.
     */
    private const NONCE_GUARD = <<<'JS'
document.addEventListener('htmx:beforeSwap', function (e) {
    var d = e.detail, html = d.serverResponse;
    if (typeof html !== 'string' || !/<(script|style)\b/i.test(html)) { return; }
    var nonce = d.xhr ? d.xhr.getResponseHeader('HX-Nonce') : null;
    var isDocument = /<html[\s>]/i.test(html);
    var root = isDocument ? new DOMParser().parseFromString(html, 'text/html') : document.createElement('template');
    if (!isDocument) { root.innerHTML = html; }
    var scope = isDocument ? root : root.content, changed = false;
    var pageNonce = window.htmx && htmx.config.inlineStyleNonce;
    scope.querySelectorAll('script, style').forEach(function (el) {
        if (!nonce || el.getAttribute('nonce') !== nonce) { el.parentNode.removeChild(el); changed = true; }
        else if (el.tagName === 'STYLE' && pageNonce && pageNonce !== nonce) { el.setAttribute('nonce', pageNonce); changed = true; }
    });
    if (changed) {
        d.serverResponse = isDocument ? '<!DOCTYPE html>' + root.documentElement.outerHTML : root.innerHTML;
    }
});
JS;

    public function isRequired(): bool
    {
        return $this->requirement()->required;
    }

    /** The current render frame's requirement; the own one outside every render. */
    private function requirement(): HtmxRequirement
    {
        if (Contexts::depth() === 0) {
            return $this->own;
        }

        return Contexts::frame()->slot(HtmxRequirement::class, fn (): HtmxRequirement => new HtmxRequirement($this->mode === 'always'));
    }
}
